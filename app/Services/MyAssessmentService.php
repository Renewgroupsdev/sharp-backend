<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\CourseStatus;
use App\Enums\QuestionType;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student taking quizzes. The student sees the published quizzes, assignments and assessments of the courses their
 * batch takes. A quiz is attempted in three steps: start (fixes the question order and the deadline), submit (marked on
 * the server, never trusting the client) and review (what the quiz setup allows). The quiz setup decides the timer, the
 * attempt limit, random order, whether the score and the answers are shown afterwards.
 */
class MyAssessmentService
{
    /** A late submit is still accepted this long after the deadline (network and clock slack). */
    private const GRACE_SECONDS = 60;

    public function __construct(private readonly QuizScorer $scorer, private readonly ModuleGateService $gate, private readonly NotificationService $notifications, private readonly QuizQuestionPicker $picker) {}

    /** @return array<string, mixed> the quiz setup with the defaults filled in */
    private function setupOf(Assessment $a): array
    {
        return ($a->setup ?? []) + AssessmentService::DEFAULT_SETUP;
    }

    /** Published assessments of the courses the student's batch takes. */
    public function visible(User $student): Builder
    {
        $titles = Course::query()
            ->where('status', CourseStatus::Published->value)
            ->whereHas('batches', fn (Builder $b) => $b->where('batches.id', $student->batch_id ?? 0))
            ->pluck('title');

        return Assessment::query()->where('status', AssessmentStatus::Published->value)->whereIn('course', $titles);
    }

    private function find(User $student, int $id): Assessment
    {
        return $this->visible($student)->with('groups:id')->whereKey($id)->first() ?? abort(404, 'Assessment not found.');
    }

    private function findQuiz(User $student, int $id): Assessment
    {
        $assessment = $this->find($student, $id);
        abort_unless($assessment->usesQuestions(), 422, 'Only quizzes and assessments can be attempted here.');

        return $assessment;
    }

    /** An attempt whose time ran out long ago was never submitted: it counts as used, with nothing marked. */
    private function closeExpired(User $student): void
    {
        QuizAttempt::where('student_id', $student->id)->whereNull('submitted_at')->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subSeconds(self::GRACE_SECONDS))
            ->each(fn (QuizAttempt $a) => $a->update([
                'submitted_at' => $a->expires_at, 'time_taken_sec' => $a->started_at->diffInSeconds($a->expires_at),
                'answers' => [], 'passed' => false, 'result_percent' => 0,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function list(User $student): Collection
    {
        $this->closeExpired($student);
        $assessments = $this->visible($student)->orderBy('course')->orderBy('id')->get();
        $ids = $assessments->pluck('id');

        $stats = QuizAttempt::where('student_id', $student->id)->whereIn('assessment_id', $ids)
            ->selectRaw('assessment_id, COUNT(*) FILTER (WHERE submitted_at IS NOT NULL) AS used, MAX(result_percent) FILTER (WHERE submitted_at IS NOT NULL) AS best, BOOL_OR(passed) FILTER (WHERE submitted_at IS NOT NULL) AS passed, MAX(id) FILTER (WHERE submitted_at IS NULL) AS open_id')
            ->groupBy('assessment_id')->get()->keyBy('assessment_id');

        $counts = DB::table('assessment_question_group as g')->join('questions as q', 'q.question_group_id', '=', 'g.question_group_id')
            ->whereIn('g.assessment_id', $ids)->groupBy('g.assessment_id')->selectRaw('g.assessment_id, COUNT(*) AS n')->pluck('n', 'assessment_id');

        $locked = $this->gate->lockedQuizzes($student, $assessments);
        $latestSubmissions = DB::table('assessment_submissions as s')
            ->where('s.student_id', $student->id)
            ->whereIn('s.assessment_id', $ids)
            ->whereRaw('s.submission_no = (SELECT MAX(s2.submission_no) FROM assessment_submissions s2 WHERE s2.assessment_id = s.assessment_id AND s2.student_id = ?)', [$student->id])
            ->select('s.assessment_id', 's.id', 's.submission_no', 's.status', 's.marks_obtained', 's.submitted_at')
            ->get()->keyBy('assessment_id');

        return $assessments->map(fn (Assessment $a) => $this->info($a, $stats->get($a->id), (int) ($counts[$a->id] ?? 0)) + [
            'locked_reason' => $locked[$a->id] ?? null,
            'latest_submission' => $latestSubmissions->get($a->id) ? [
                'id' => (int) $latestSubmissions[$a->id]->id,
                'submission_no' => (int) $latestSubmissions[$a->id]->submission_no,
                'status' => $latestSubmissions[$a->id]->status,
                'marks_obtained' => $latestSubmissions[$a->id]->marks_obtained,
                'submitted_at' => $latestSubmissions[$a->id]->submitted_at,
            ] : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function info(Assessment $a, ?object $stat, int $questions): array
    {
        $setup = $this->setupOf($a);
        $quiz = $a->usesQuestions();
        // With random order and a question count set, each student gets that many questions, not all of them.
        $served = $setup['random'] && (int) ($setup['question_count'] ?? 0) > 0 ? min((int) $setup['question_count'], $questions) : $questions;

        return [
            'id' => $a->id,
            'type' => $a->type,
            'title' => $a->title,
            'course' => $a->course,
            'module' => $a->module,
            'topic' => $a->topic,
            'description' => $a->description,
            'due_date' => $a->due_date?->toDateString(),
            'attachment' => $a->attachmentSummary(),
            'max_marks' => $a->max_marks,
            'min_percent' => $a->min_percent,
            'questions_count' => $quiz ? $served : null,
            'time_limit_sec' => $quiz && (int) $setup['per_question'] > 0 ? (int) $setup['per_question'] * 60 * $served : null,
            'attempts_allowed' => $quiz ? $a->attemptLimit() : null,
            'attempts_used' => $quiz ? (int) ($stat->used ?? 0) : null,
            'best_percent' => $stat?->best === null ? null : (int) $stat->best,
            'passed' => $stat?->passed === null ? null : (bool) $stat->passed,
            'in_progress_attempt_id' => $stat?->open_id ? (int) $stat->open_id : null,
            'same_page' => (bool) $setup['same_page'],
        ];
    }

    /** The quiz with this student's attempts. @return array<string, mixed> */
    public function detail(User $student, int $id): array
    {
        $a = $this->find($student, $id);
        $this->closeExpired($student);
        $questions = $a->usesQuestions() ? $a->quizQuestionCount() : 0;
        $locked = $this->gate->lockedQuizzes($student, collect([$a]));

        $attempts = QuizAttempt::where(['assessment_id' => $a->id, 'student_id' => $student->id])->orderBy('attempt_no')->get();
        $stat = (object) [
            'used' => $attempts->whereNotNull('submitted_at')->count(),
            'best' => $attempts->whereNotNull('submitted_at')->max('result_percent'),
            'passed' => $attempts->whereNotNull('submitted_at')->contains('passed', true) ?: ($attempts->whereNotNull('submitted_at')->isEmpty() ? null : false),
            'open_id' => $attempts->whereNull('submitted_at')->first()?->id,
        ];

        return [
            'assessment' => $this->info($a, $stat, $questions) + ['locked_reason' => $locked[$a->id] ?? null],
            'attempts' => $attempts->whereNotNull('submitted_at')->values()->map(fn (QuizAttempt $t) => [
                'id' => $t->id, 'attempt_no' => $t->attempt_no, 'result_percent' => $t->result_percent, 'passed' => $t->passed,
                'time_taken_sec' => $t->time_taken_sec, 'submitted_at' => $t->submitted_at,
            ])->all(),
            'in_progress' => ($open = $attempts->whereNull('submitted_at')->first()) ? ['id' => $open->id, 'expires_at' => $open->expires_at] : null,
        ];
    }

    /**
     * Starts an attempt, or resumes the one in progress (a refresh never burns an attempt).
     *
     * @return array{attempt: array<string, mixed>, questions: list<array<string, mixed>>}
     */
    public function start(User $student, int $id): array
    {
        $a = $this->findQuiz($student, $id);
        $this->gate->assertQuizOpen($student, $a);
        $this->closeExpired($student);
        $setup = $this->setupOf($a);

        $attempt = QuizAttempt::where(['assessment_id' => $a->id, 'student_id' => $student->id])->whereNull('submitted_at')->first();

        if (! $attempt) {
            $used = QuizAttempt::where(['assessment_id' => $a->id, 'student_id' => $student->id])->count();
            if (($limit = $a->attemptLimit()) !== null && $used >= $limit) {
                throw ValidationException::withMessages(['attempt' => 'You have used every attempt for this quiz.']);
            }

            $ids = $this->questionIdsFor($a, $student, $used + 1, $setup);
            if (! $ids) {
                throw ValidationException::withMessages(['attempt' => 'This quiz has no questions yet.']);
            }

            $attempt = QuizAttempt::create([
                'assessment_id' => $a->id,
                'student_id' => $student->id,
                'attempt_no' => $used + 1,
                'question_order' => $ids,
                'started_at' => now(),
                'expires_at' => (int) $setup['per_question'] > 0 ? now()->addSeconds((int) $setup['per_question'] * 60 * count($ids)) : null,
            ]);
        }

        return ['attempt' => $this->attemptInfo($attempt), 'questions' => $this->served($attempt)];
    }

    /**
     * The questions this student gets for this attempt. Fixed order (by id) when random is off; otherwise a per-student
     * random list from QuizQuestionPicker, limited to `question_count` questions when that is set.
     *
     * @param  array<string, mixed>  $setup
     * @return list<int>
     */
    private function questionIdsFor(Assessment $a, User $student, int $attemptNo, array $setup): array
    {
        $rows = Question::whereIn('question_group_id', $a->groups->pluck('id'))->orderBy('id')->get(['id', 'question_group_id']);
        if (! $setup['random']) {
            return $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $pools = $rows->groupBy('question_group_id')->map(fn ($g) => $g->pluck('id')->map(fn ($id) => (int) $id)->all())->all();
        $seen = QuizAttempt::where(['assessment_id' => $a->id, 'student_id' => $student->id])->pluck('question_order')->flatten()->map(fn ($id) => (int) $id)->all();

        return $this->picker->pick($pools, $student->id, $a->id, $attemptNo, (int) ($setup['question_count'] ?? 0), $seen);
    }

    /** @return array<string, mixed> */
    private function attemptInfo(QuizAttempt $attempt): array
    {
        return ['id' => $attempt->id, 'attempt_no' => $attempt->attempt_no, 'started_at' => $attempt->started_at, 'expires_at' => $attempt->expires_at];
    }

    /** @return list<array<string, mixed>> the attempt's questions in the order fixed at start, without answers */
    private function served(QuizAttempt $attempt): array
    {
        $byId = Question::whereIn('id', $attempt->question_order)->get()->keyBy('id');

        return collect($attempt->question_order)->filter(fn ($id) => $byId->has($id))->map(fn ($id) => $this->scorer->forLearner($byId[$id]))->values()->all();
    }

    /**
     * Marks the attempt on the server and returns what the quiz setup lets the student see.
     *
     * @param  list<array{question_id: int, answer: mixed}>  $answers
     * @return array<string, mixed>
     */
    public function submit(User $student, int $id, int $attemptId, array $answers): array
    {
        $a = $this->findQuiz($student, $id);
        $setup = $this->setupOf($a);

        $attempt = DB::transaction(function () use ($a, $student, $attemptId, $answers) {
            $attempt = QuizAttempt::where(['id' => $attemptId, 'assessment_id' => $a->id, 'student_id' => $student->id])->lockForUpdate()->first()
                ?? abort(404, 'Attempt not found.');

            if (! $attempt->isOpen()) {
                throw ValidationException::withMessages(['attempt' => 'This attempt was already submitted.']);
            }
            if ($attempt->expires_at && now()->gt($attempt->expires_at->copy()->addSeconds(self::GRACE_SECONDS))) {
                $this->closeExpired($student);
                throw ValidationException::withMessages(['attempt' => 'Time is up: this attempt was closed without marks.']);
            }

            return $this->mark($a, $attempt, $answers);
        });

        $this->recordBest($a, $student, $attempt);

        $result = $this->resultOf($a, $attempt, $setup);
        $this->notifications->notify('quiz_scored', $student->id, [
            'quiz' => $a->title,
            'result' => ! empty($result['show_score']) && ! $attempt->needs_manual_grading
                ? "You scored {$attempt->marks}/{$attempt->total} ({$attempt->result_percent}%): ".($attempt->passed ? 'passed.' : 'not passed.')
                : 'Your answers were received'.($attempt->needs_manual_grading ? ' and some need a mentor to mark them.' : '.'),
        ], ['url' => '/app/assessments']);

        return $result;
    }

    /** Scores every question of the attempt and stores the outcome on it. */
    private function mark(Assessment $a, QuizAttempt $attempt, array $answers): QuizAttempt
    {
        $given = collect($answers)->mapWithKeys(fn ($row) => [(int) ($row['question_id'] ?? 0) => $row['answer'] ?? null]);
        $questions = Question::whereIn('id', $attempt->question_order)->get()->keyBy('id');

        $marks = $total = $pendingMarks = 0;
        $sheet = [];
        foreach ($attempt->question_order as $qid) {
            $q = $questions->get($qid);
            if (! $q) {
                continue;
            }
            $answer = $given->get($qid);
            $graded = $this->scorer->grade($q, $answer);
            $total += $q->marks;
            $marks += $graded['marks'];
            $pendingMarks += $graded['correct'] === null ? $q->marks : 0;
            $sheet[] = ['question_id' => $qid, 'answer' => $answer, 'marks' => $graded['marks'], 'correct' => $graded['correct']];
        }

        // Answers a mentor still has to mark do not count against the student yet.
        $gradable = $total - $pendingMarks;
        $percent = $gradable > 0 ? (int) round($marks / $gradable * 100) : 0;

        $attempt->update([
            'submitted_at' => now(),
            'time_taken_sec' => (int) $attempt->started_at->diffInSeconds(now()),
            'marks' => $marks,
            'total' => $total,
            'result_percent' => min(100, $percent),
            'passed' => $percent >= $a->min_percent && $gradable > 0,
            'needs_manual_grading' => $pendingMarks > 0,
            'answers' => $sheet,
        ]);

        return $attempt;
    }

    /** quiz_results keeps the best attempt (the one the quiz report and certificates read) and how many attempts were made. */
    private function recordBest(Assessment $a, User $student, QuizAttempt $attempt): void
    {
        $result = QuizResult::firstOrNew(['assessment_id' => $a->id, 'student_id' => $student->id]);
        if (! $result->exists || $attempt->result_percent >= $result->result_percent) {
            $result->fill(['marks' => $attempt->marks, 'total' => $attempt->total, 'result_percent' => $attempt->result_percent]);
        }
        $result->attempts = $attempt->attempt_no;
        $result->save();
    }

    /** @param  array<string, mixed>  $setup @return array<string, mixed> */
    private function resultOf(Assessment $a, QuizAttempt $attempt, array $setup): array
    {
        $used = QuizAttempt::where(['assessment_id' => $a->id, 'student_id' => $attempt->student_id])->whereNotNull('submitted_at')->count();
        $out = [
            'attempt_id' => $attempt->id,
            'attempt_no' => $attempt->attempt_no,
            'submitted' => true,
            'show_score' => (bool) $setup['score_report'],
            'needs_manual_grading' => $attempt->needs_manual_grading,
            'attempts_left' => ($limit = $a->attemptLimit()) === null ? null : max(0, $limit - $used),
            'review_allowed' => (bool) $setup['review'],
        ];

        if ($setup['score_report']) {
            $out += [
                'marks' => $attempt->marks, 'total' => $attempt->total, 'result_percent' => $attempt->result_percent,
                'passed' => $attempt->passed, 'min_percent' => $a->min_percent, 'time_taken_sec' => $attempt->time_taken_sec,
            ];
        }
        if ($setup['score_report'] && $setup['total_correct']) {
            $out += [
                'correct_count' => collect($attempt->answers)->where('correct', true)->count(),
                'question_count' => count($attempt->question_order),
            ];
        }

        return $out;
    }

    /**
     * The questions of a finished attempt (the latest, or the one asked for) with the student's answers. The answer
     * sheet and explanations follow the quiz setup.
     *
     * @return array<string, mixed>
     */
    public function review(User $student, int $id, ?int $attemptId): array
    {
        $a = $this->findQuiz($student, $id);
        $setup = $this->setupOf($a);
        abort_unless($setup['review'], 403, 'Review is switched off for this quiz.');

        $attempt = QuizAttempt::where(['assessment_id' => $a->id, 'student_id' => $student->id])->whereNotNull('submitted_at')
            ->when($attemptId, fn (Builder $q) => $q->whereKey($attemptId))->orderByDesc('attempt_no')->first()
            ?? abort(404, 'No finished attempt to review.');

        $mode = (string) $setup['answer_sheet'];
        $sheet = $setup['answer_sheets'] && $mode !== 'none';
        $byId = Question::whereIn('id', $attempt->question_order)->get()->keyBy('id');
        $given = collect($attempt->answers)->keyBy('question_id');

        $questions = collect($attempt->question_order)->filter(fn ($qid) => $byId->has($qid))->map(function ($qid) use ($byId, $given, $sheet, $mode, $setup) {
            $q = $byId[$qid];
            $row = $given->get($qid, ['answer' => null, 'marks' => 0, 'correct' => false]);
            $entry = $this->scorer->forReview($q) + ['your_answer' => $row['answer'], 'marks_awarded' => $row['marks'], 'correct' => $row['correct']];

            if ($sheet && ($mode === 'correct' || $row['correct'] !== true)) {
                $entry['correct_answer'] = $this->scorer->answerSheet($q);
            }
            if ($sheet && $setup['explanations'] && $q->explanation) {
                $entry['explanation'] = $q->explanation;
            }

            return $entry;
        })->values()->all();

        return ['attempt' => ['id' => $attempt->id] + $this->resultOf($a, $attempt, $setup), 'questions' => $questions];
    }
}
