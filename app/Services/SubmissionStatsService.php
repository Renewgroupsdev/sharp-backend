<?php

namespace App\Services;

use App\Enums\AssessmentType;
use App\Enums\Role;
use App\Models\AssessmentSubmission;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\StudentScope;
use Illuminate\Database\Eloquent\Builder;

/** Submission counts for a dashboard: a student sees their own, a mentor their batch, staff everyone. */
class SubmissionStatsService
{
    /** @return array{scope: string, quiz: array<string, int>, assignment: array<string, int>, assessment: array<string, int>} */
    public function for(User $actor): array
    {
        $stats = [
            'scope' => match ($actor->role) {
                Role::Student => 'own',
                Role::Mentor => 'batch',
                default => 'all',
            },
            'quiz' => $this->attemptCounts($actor, AssessmentType::Quiz),
            'assignment' => $this->emptyCounts(),
            'assessment' => $this->attemptCounts($actor, AssessmentType::Assessment),
        ];

        $rows = $this->scoped(AssessmentSubmission::query(), $actor, 'assessment_submissions.student_id')
            ->join('assessments', 'assessments.id', '=', 'assessment_submissions.assessment_id')
            ->where('assessments.type', AssessmentType::Assignment->value)
            ->selectRaw('assessments.type as type, assessment_submissions.status as status, count(*) as n')
            ->groupBy('assessments.type', 'assessment_submissions.status')
            ->toBase() // plain rows: the model would cast `status` to its enum
            ->get();

        foreach ($rows as $row) {
            $stats[$row->type]['total'] += (int) $row->n;
            $stats[$row->type][$row->status] = (int) $row->n;
        }

        return $stats;
    }

    /** @return array{total: int, needs_review: int, passed: int, not_passed: int} submitted attempts; long answers wait for a mentor */
    private function attemptCounts(User $actor, AssessmentType $type): array
    {
        $attempts = $this->scoped(QuizAttempt::query()->whereNotNull('submitted_at')
            ->whereHas('assessment', fn (Builder $a) => $a->where('type', $type->value)), $actor, 'student_id');

        return [
            'total' => (clone $attempts)->count(),
            'needs_review' => (clone $attempts)->where('needs_manual_grading', true)->count(),
            'passed' => (clone $attempts)->where('needs_manual_grading', false)->where('passed', true)->count(),
            'not_passed' => (clone $attempts)->where('needs_manual_grading', false)->where(fn (Builder $q) => $q->where('passed', false)->orWhereNull('passed'))->count(),
        ];
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return ['total' => 0, 'submitted' => 0, 'under_review' => 0, 'graded' => 0, 'returned' => 0];
    }

    private function scoped(Builder $query, User $actor, string $studentColumn): Builder
    {
        return StudentScope::apply($query, $actor, $studentColumn);
    }
}
