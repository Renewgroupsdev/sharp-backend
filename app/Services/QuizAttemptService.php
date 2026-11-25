<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\QuizResult;
use App\Models\User;
use App\Support\StudentScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff and mentor view of quiz attempts: who attempted which quiz, how it went, and the exact questions each student
 * was served with their answers. A mentor sees their own batch; admins see everyone (see StudentScope).
 */
class QuizAttemptService
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly QuizScorer $scorer,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Attempts the actor may see, filtered. `outcome` is left out so counts() can reuse this for every outcome.
     *
     * @param  array{assessment_id?: int|null, student_id?: int|null, type?: string|null, q?: string|null}  $filters
     */
    private function base(User $actor, array $filters): Builder
    {
        $query = QuizAttempt::query()
            ->with(['assessment:id,type,title,course,min_percent', 'student:id,public_id,name,batch_id'])
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->whereHas('assessment', fn (Builder $a) => $a->where('type', $type)))
            ->when($filters['assessment_id'] ?? null, fn (Builder $q, $id) => $q->where('quiz_attempts.assessment_id', $id))
            ->when($filters['student_id'] ?? null, fn (Builder $q, $id) => $q->where('quiz_attempts.student_id', $id))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->whereHas('student', fn (Builder $s) => $s
                ->where('name', 'ilike', "%{$term}%")->orWhere('public_id', 'ilike', "%{$term}%")));

        return StudentScope::apply($query, $actor, 'quiz_attempts.student_id');
    }

    /** @param  array{assessment_id?: int|null, student_id?: int|null, q?: string|null, outcome?: string|null, per_page?: int|null}  $filters */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->base($actor, $filters)
            ->when($filters['outcome'] ?? null, fn (Builder $q, string $o) => $q->outcome($o))
            ->orderByDesc('quiz_attempts.started_at')->orderByDesc('quiz_attempts.id')
            ->paginate(min((int) ($filters['per_page'] ?? self::PER_PAGE), 100));
    }

    /**
     * Attempts per outcome for the same filters (outcome itself excluded), for the summary tiles.
     *
     * @return array<string, int>
     */
    public function counts(User $actor, array $filters): array
    {
        $counts = ['total' => 0];
        foreach (QuizAttempt::OUTCOMES as $outcome) {
            $counts[$outcome] = $this->base($actor, $filters)->outcome($outcome)->count();
            $counts['total'] += $counts[$outcome];
        }

        return $counts;
    }

    /**
     * One attempt in full: the questions in the order this student got them, their answer, the right answer, the marks
     * and the explanation. Staff always see the answer sheet; it does not follow the student-facing quiz setup.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(QuizAttempt $attempt): array
    {
        $byId = Question::whereIn('id', $attempt->question_order ?? [])->get()->keyBy('id');
        $given = collect($attempt->answers ?? [])->keyBy('question_id');

        return collect($attempt->question_order ?? [])
            ->filter(fn ($id) => $byId->has($id))
            ->values()
            ->map(function ($id, int $i) use ($byId, $given) {
                $q = $byId[$id];
                $row = $given->get($id);

                return $this->scorer->forReview($q) + [
                    'position' => $i + 1,
                    'answered' => $row !== null && $row['answer'] !== null && $row['answer'] !== '' && $row['answer'] !== [],
                    'your_answer' => $row['answer'] ?? null,
                    'marks_awarded' => $row['marks'] ?? 0,
                    'correct' => $row['correct'] ?? false, // null: waiting for a mentor to mark
                    'needs_marking' => $q->type === QuestionType::LongAnswer && $row !== null && ($row['correct'] ?? null) === null,
                    'marked' => ! empty($row['graded_by']),
                    'feedback' => $row['feedback'] ?? null,
                    'marked_by' => $row['graded_by_name'] ?? null,
                    'correct_answer' => $this->scorer->answerSheet($q),
                    'explanation' => $q->explanation,
                ];
            })->all();
    }

    /**
     * A mentor or admin marks one long answer of a submitted attempt. Re-marking is allowed. The attempt's marks, percent,
     * pass flag and "needs marking" flag are worked out again, and the student's best result is refreshed.
     * The student is told once every long answer of the attempt has been marked.
     */
    public function gradeQuestion(User $actor, QuizAttempt $attempt, int $questionId, int $marks, ?string $feedback): QuizAttempt
    {
        $this->assertCanView($actor, $attempt);

        $wasPending = false;
        $attempt = DB::transaction(function () use ($actor, $attempt, $questionId, $marks, $feedback, &$wasPending) {
            $locked = QuizAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail()->load('assessment:id,type,title,min_percent');

            if ($locked->submitted_at === null || $locked->outcome() === QuizAttempt::TIMED_OUT) {
                throw ValidationException::withMessages(['attempt' => 'Only a submitted attempt can be marked.']);
            }
            $question = Question::find($questionId);
            if (! $question || ! in_array($questionId, $locked->question_order ?? [], true) || $question->type !== QuestionType::LongAnswer) {
                throw ValidationException::withMessages(['question_id' => 'Only a long answer of this attempt can be marked by hand.']);
            }
            if ($marks > $question->marks) {
                throw ValidationException::withMessages(['marks' => "This question is worth {$question->marks} marks."]);
            }

            $wasPending = $locked->needs_manual_grading;
            $sheet = collect($locked->answers ?? [])->keyBy('question_id');
            $row = $sheet->get($questionId, ['question_id' => $questionId, 'answer' => null]);
            $sheet->put($questionId, array_merge($row, [
                'marks' => $marks,
                'correct' => $marks >= $question->marks,
                'feedback' => $feedback !== null && trim($feedback) !== '' ? trim($feedback) : null,
                'graded_by' => $actor->id,
                'graded_by_name' => $actor->name,
                'graded_at' => now()->toISOString(),
            ]));

            // Same maths as a first marking (MyAssessmentService::mark): answers still waiting do not count against the student.
            $given = (int) $sheet->sum('marks');
            $pending = (int) Question::whereIn('id', $sheet->filter(fn ($r) => ($r['correct'] ?? null) === null)->keys())->sum('marks');
            $gradable = $locked->total - $pending;
            $percent = $gradable > 0 ? min(100, (int) round($given / $gradable * 100)) : 0;

            $locked->update([
                'answers' => $sheet->values()->all(),
                'marks' => $given,
                'result_percent' => $percent,
                'passed' => $gradable > 0 && $percent >= $locked->assessment->min_percent,
                'needs_manual_grading' => $pending > 0,
            ]);
            $this->syncBest($locked);

            return $locked;
        });

        $this->audit->record($actor, 'Marked a quiz answer', (string) $attempt->id, "{$attempt->assessment->title} · question {$questionId} · {$marks} marks");

        if ($wasPending && ! $attempt->needs_manual_grading) {
            $this->notifications->notify('quiz_scored', $attempt->student_id, [
                'quiz' => $attempt->assessment->title,
                'result' => "Your answers were marked: {$attempt->marks}/{$attempt->total} ({$attempt->result_percent}%): ".($attempt->passed ? 'passed.' : 'not passed.'),
            ], ['url' => '/app/assessments']);
        }

        return $attempt;
    }

    /** quiz_results keeps the student's best submitted attempt; marking can raise or lower an attempt, so look at them all again. */
    private function syncBest(QuizAttempt $changed): void
    {
        $attempts = QuizAttempt::where(['assessment_id' => $changed->assessment_id, 'student_id' => $changed->student_id])->whereNotNull('submitted_at')->get();
        $best = $attempts->sortByDesc(fn (QuizAttempt $a) => [$a->result_percent, $a->id])->first();
        if (! $best) {
            return;
        }
        QuizResult::updateOrCreate(
            ['assessment_id' => $changed->assessment_id, 'student_id' => $changed->student_id],
            ['marks' => $best->marks, 'total' => $best->total, 'result_percent' => $best->result_percent, 'attempts' => $attempts->max('attempt_no')],
        );
    }

    /** Opening a record outside the actor's scope is refused the same way for every attempt endpoint. */
    public function assertCanView(User $actor, QuizAttempt $attempt): void
    {
        $attempt->loadMissing('student:id,public_id,name,batch_id');
        abort_unless(StudentScope::allows($actor, $attempt->student), 403, 'You cannot view this student.');
    }
}
