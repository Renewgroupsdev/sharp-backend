<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\QuestionGroup;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Quizzes, assignments and assessments, their quiz setup and the quiz results behind the report. */
class AssessmentService
{
    public const DEFAULT_SETUP = [
        'per_question' => 1, 'question_count' => 0, 'review' => true, 'random' => false, 'multi' => true, 'same_page' => false,
        'explanations' => true, 'answer_sheets' => true, 'score_report' => true, 'total_correct' => true,
        'answer_sheet' => 'correct',
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly AssessmentAttachmentService $files, private readonly NotificationService $notifications) {}

    /** @return Collection<int, Assessment> */
    public function list(array $filters = []): Collection
    {
        return Assessment::query()
            ->with('groups:id')
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['course'] ?? null, fn ($q, $course) => $q->where('course', $course))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")->orWhere('course', 'like', "%{$term}%")->orWhere('module', 'like', "%{$term}%")))
            ->orderBy('id')
            ->get();
    }

    public function save(User $actor, array $data, ?Assessment $assessment = null): Assessment
    {
        $quiz = in_array($data['type'], [AssessmentType::Quiz->value, AssessmentType::Assessment->value], true);
        $assessment ??= new Assessment(['attempts' => 1, 'min_percent' => 60]);

        $wasPublished = $assessment->exists && $assessment->status === AssessmentStatus::Published;
        $assessment->fill([
            'type' => $data['type'],
            'title' => $data['title'],
            'course' => $data['course'],
            'module' => $data['module'] ?? null,
            'topic' => $data['topic'] ?? null,
            'status' => $data['status'] ?? $assessment->status?->value ?? 'draft',
            // A quiz has no submit date, description or attachment; its marks come from its question groups.
            'due_date' => $quiz ? null : $data['due_date'],
            'description' => $quiz ? null : ($data['description'] ?? null),
        ]);
        if ($quiz) {
            $this->files->clear($assessment);
        }
        if (! $quiz) {
            $assessment->max_marks = $data['max_marks'];
            $assessment->min_percent = $data['min_percent'] ?? $assessment->min_percent;
        } elseif ($assessment->setup === null) {
            $assessment->setup = self::DEFAULT_SETUP + ['min_percent' => $assessment->min_percent];
        }
        $assessment->save();

        if ($quiz && array_key_exists('group_ids', $data)) {
            $this->syncGroups($assessment, $data['group_ids'] ?? []);
        }
        $this->refreshQuizTotal($assessment);

        $this->audit->record($actor, ($assessment->wasRecentlyCreated ? 'Added ' : 'Updated ').$data['type'], (string) $assessment->id, $assessment->title);
        if (! $wasPublished && $assessment->status === AssessmentStatus::Published) {
            $this->announce($assessment);
        }

        return $assessment->refresh()->load('groups:id');
    }

    /** Tells the students of the batches that take the course that something new is available. */
    private function announce(Assessment $assessment): void
    {
        $course = Course::where('title', $assessment->course)->first();
        if (! $course) {
            return;
        }
        $ids = User::where('role', Role::Student->value)->where('status', '<>', UserStatus::Suspended->value)
            ->whereIn('batch_id', $course->batches()->pluck('batches.id'))->pluck('id')->all();

        $type = $assessment->type?->value ?? $assessment->type;
        $this->notifications->notify('assessment_published', $ids, [
            'kind' => $type === AssessmentType::Quiz->value ? 'quiz' : ($type === AssessmentType::Assignment->value ? 'assignment' : 'assessment'),
            'title' => $assessment->title, 'course' => $assessment->course,
            'due' => $assessment->due_date ? ' Due '.$assessment->due_date->format('d M Y').'.' : '',
        ], ['url' => '/app/assessments']);
    }

    /** Courses are matched by title, so renaming one re-points its quizzes, assignments and assessments. */
    public function renameCourse(User $actor, string $from, string $to): int
    {
        $updated = Assessment::where('course', $from)->update(['course' => $to]);
        if ($updated > 0) {
            $this->audit->record($actor, 'Renamed course on assessments', null, "{$from} → {$to} ({$updated})");
        }

        return $updated;
    }

    public function delete(User $actor, Assessment $assessment): void
    {
        $title = $assessment->title;
        $id = (string) $assessment->id;
        $this->files->clear($assessment);
        $assessment->delete();

        $this->audit->record($actor, 'Deleted assessment', $id, $title);
    }

    public function saveSetup(User $actor, Assessment $assessment, array $data): Assessment
    {
        $setup = collect($data)->except(['group_ids', 'min_percent'])->all();
        if ($assessment->usesQuestions()) {
            $setup['min_percent'] = $data['min_percent'] ?? $assessment->min_percent;
            $assessment->min_percent = $setup['min_percent'];
            if (array_key_exists('group_ids', $data)) {
                $this->syncGroups($assessment, $data['group_ids'] ?? []);
            }
        }
        $assessment->setup = $setup;
        // Quizzes have no attempt cap: `multi` on means unlimited retries (stored as 0), off means a single attempt.
        $assessment->attempts = $setup['multi'] ? 0 : 1;
        $assessment->save();
        $this->refreshQuizTotal($assessment);

        $this->audit->record($actor, 'Updated quiz setup', (string) $assessment->id, $assessment->title);

        return $assessment->refresh()->load('groups:id');
    }

    /** A quiz draws only from quiz groups and an assessment only from assessment groups. @param  list<int>  $ids */
    private function syncGroups(Assessment $assessment, array $ids): void
    {
        $type = $assessment->type?->value ?? $assessment->type;
        $valid = QuestionGroup::whereIn('id', $ids)->where('type', $type)->count();
        if ($valid !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['group_ids' => "Choose question groups that belong to this {$type}."]);
        }
        $assessment->groups()->sync($ids);
    }

    /** A quiz is marked out of the questions in its groups; call after its groups or any question changes. */
    public function refreshQuizTotal(Assessment $assessment): void
    {
        if ($assessment->usesQuestions()) {
            $assessment->update(['max_marks' => $assessment->quizTotal()]);
        }
    }

    public function refreshAllQuizTotals(): void
    {
        Assessment::whereIn('type', [AssessmentType::Quiz->value, AssessmentType::Assessment->value])->each(fn (Assessment $a) => $this->refreshQuizTotal($a));
    }

    /** @return Collection<int, QuizResult> */
    public function results(array $filters = []): Collection
    {
        return QuizResult::query()
            ->with(['assessment:id,title,course,min_percent', 'student:id,public_id,name'])
            ->when($filters['assessment_id'] ?? null, fn ($q, $id) => $q->where('assessment_id', $id))
            ->when($filters['course'] ?? null, fn ($q, $c) => $q->whereHas('assessment', fn ($a) => $a->where('course', $c)))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('student', fn ($s) => $s
                ->where('name', 'like', "%{$term}%")->orWhere('public_id', 'like', "%{$term}%")))
            ->orderByDesc('id')
            ->get();
    }

    /** Records (or replaces) a student's result on a quiz; the percentage is derived from the quiz's current total. */
    public function recordResult(User $actor, array $data): QuizResult
    {
        $quiz = Assessment::where('type', AssessmentType::Quiz->value)->findOrFail($data['assessment_id']);
        $total = $quiz->quizTotal();
        $result = QuizResult::updateOrCreate(
            ['assessment_id' => $quiz->id, 'student_id' => $data['student_id']],
            [
                'marks' => min($data['marks'], $total),
                'total' => $total,
                'result_percent' => $total ? (int) round(min($data['marks'], $total) / $total * 100) : 0,
                'attempts' => $data['attempts'] ?? 1,
            ],
        );

        $this->audit->record($actor, 'Recorded quiz result', (string) $result->id, $quiz->title);

        return $result->load(['assessment:id,title,course,min_percent', 'student:id,public_id,name']);
    }
}
