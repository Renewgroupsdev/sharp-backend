<?php

namespace App\Services;

use App\Enums\LearningStatus;
use App\Enums\Role;
use App\Models\LearningProgress;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LearningService
{
    public function __construct(private readonly AuditLogger $audit, private readonly CertificateService $certificates) {}

    /**
     * One row per student × course. A student is enrolled in every live course mapped to their batch, so the
     * list is derived from batch_course and joined to whatever progress is stored (0 when none).
     */
    private function query(): Builder
    {
        return DB::table('batch_course as bc')
            ->join('courses as c', fn ($j) => $j->on('c.id', '=', 'bc.course_id')->whereNull('c.deleted_at'))
            ->join('users as u', fn ($j) => $j->on('u.batch_id', '=', 'bc.batch_id')->where('u.role', Role::Student->value))
            ->leftJoin('learning_progress as lp', fn ($j) => $j->on('lp.user_id', '=', 'u.id')->on('lp.course_id', '=', 'c.id'))
            ->select([
                'u.id as user_id', 'u.public_id as student_id', 'u.name', 'u.org',
                'c.id as course_id', 'c.title as course',
                DB::raw('COALESCE(lp.progress, 0) as progress'),
                DB::raw('COALESCE(lp.materials_completed, 0) as materials'),
                'lp.last_accessed_at',
            ])
            ->orderBy('u.name')->orderBy('c.title');
    }

    /**
     * @param  array{q?: string, course_id?: int, org?: string, status?: string}  $filters
     * @return Collection<int, object>
     */
    public function list(array $filters = []): Collection
    {
        $status = ! empty($filters['status']) ? LearningStatus::from($filters['status']) : null;

        return $this->query()
            ->when(! empty($filters['q']), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereLike('u.name', "%{$filters['q']}%")->orWhereLike('u.public_id', "%{$filters['q']}%")))
            ->when(! empty($filters['course_id']), fn (Builder $q) => $q->where('c.id', $filters['course_id']))
            ->when(! empty($filters['org']), fn (Builder $q) => $q->where('u.org', $filters['org']))
            ->when($status, fn (Builder $q) => $q->whereBetween(DB::raw('COALESCE(lp.progress, 0)'), $status->range()))
            ->get();
    }

    private function find(int $userId, int $courseId): ?object
    {
        return $this->query()->where('u.id', $userId)->where('c.id', $courseId)->first();
    }

    /** Insert or update the single progress row for (student, course); the student must be enrolled through their batch. */
    public function save(User $actor, array $data): object
    {
        $student = User::students()->where('public_id', $data['student_id'])->firstOrFail();
        $this->assertEnrolled($student, (int) $data['course_id']);

        LearningProgress::updateOrCreate(
            ['user_id' => $student->id, 'course_id' => $data['course_id']],
            [
                'progress' => $data['progress'],
                'materials_completed' => $data['materials_completed'] ?? 0,
                'last_accessed_at' => $data['last_accessed_at'] ?? now(),
            ],
        );

        $row = $this->find($student->id, (int) $data['course_id']);
        if ((int) $row->progress >= 100) {
            $this->certificates->request($student, (int) $data['course_id']);
        }
        $this->audit->record($actor, "Updated learning progress to {$row->progress}%", $student->public_id, $row->course);

        return $row;
    }

    /** Clears the stored progress; the student stays enrolled and goes back to "Not started". */
    public function reset(User $actor, string $studentPublicId, int $courseId): object
    {
        $student = User::students()->where('public_id', $studentPublicId)->firstOrFail();
        $this->assertEnrolled($student, $courseId);

        LearningProgress::where(['user_id' => $student->id, 'course_id' => $courseId])->delete();

        $row = $this->find($student->id, $courseId);
        $this->audit->record($actor, 'Reset learning progress', $student->public_id, $row->course);

        return $row;
    }

    private function assertEnrolled(User $student, int $courseId): void
    {
        if (! $this->find($student->id, $courseId)) {
            throw ValidationException::withMessages([
                'student_id' => 'This student is not enrolled in the course. Map their batch to the course first.',
            ]);
        }
    }
}
