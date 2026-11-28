<?php

namespace App\Services;

use App\Support\StudentScope;
use App\Enums\CourseStatus;
use App\Enums\Role;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseService
{
    private const FIELDS = [
        'title', 'course_category_id', 'hours', 'mentors', 'mode', 'level', 'scope', 'status',
        'description', 'outcomes', 'requirements', 'thumbnail',
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly EnrollmentService $enrollments) {}

    /**
     * Courses with their curriculum. Staff see every course (and can ask for the trash); everyone else
     * only sees published, non-deleted courses.
     *
     * @param  array{status?: string, category_id?: int, q?: string, trashed?: bool}  $filters
     * @return Collection<int, Course>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $staff = $actor->hasRole(...Role::staff());

        return Course::query()
            ->with(['category:id,name', 'batches:id,name', 'chapters.lessons'])
            ->when($staff && ! empty($filters['trashed']), fn (Builder $q) => $q->onlyTrashed())
            ->when(! $staff && ! empty($filters['trashed']), fn (Builder $q) => $q->whereRaw('1 = 0')) // the trash is for staff
            ->when(! $staff, fn (Builder $q) => $q->where('status', CourseStatus::Published->value))
            // An institution sees the courses its own students are enrolled in.
            ->when($actor->role === Role::Institution, fn (Builder $q) => $q->whereIn('id', StudentScope::collegeCourseIds($actor)))
            // A mentor sees the courses they teach.
            ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->whereIn('id', StudentScope::courseIds($actor)))
            ->when($staff && ! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['category_id']), fn (Builder $q) => $q->where('course_category_id', $filters['category_id']))
            ->search($filters['q'] ?? null)
            ->latest('id')
            ->get();
    }

    public function load(Course $course): Course
    {
        return $course->load(['category:id,name', 'batches:id,name', 'chapters.lessons']);
    }

    public function create(User $actor, array $data): Course
    {
        $course = DB::transaction(function () use ($actor, $data) {
            $course = Course::create($this->fields($data));
            $changes = $course->batches()->sync($data['batch_ids'] ?? []);
            $this->enrollments->recordChanges($actor, $course->id, $changes['attached'], $changes['detached'], 'course form');
            if (isset($data['chapters'])) {
                $this->syncChapters($course, $data['chapters']);
            }

            return $course;
        });

        $this->audit->record($actor, 'Created course', $course->code(), $course->title);

        return $this->load($course);
    }

    public function update(User $actor, Course $course, array $data): Course
    {
        DB::transaction(function () use ($actor, $course, $data) {
            $course->update($this->fields($data));
            if (array_key_exists('batch_ids', $data)) {
                $changes = $course->batches()->sync($data['batch_ids'] ?? []);
                $this->enrollments->recordChanges($actor, $course->id, $changes['attached'], $changes['detached'], 'course form');
            }
            if (array_key_exists('chapters', $data) && $data['chapters'] !== null) {
                $this->syncChapters($course, $data['chapters']);
            }
        });

        $this->audit->record($actor, 'Updated course', $course->code(), $course->title);

        return $this->load($course->refresh());
    }

    /** Replace the curriculum; ids that belong to this course are updated in place, the rest are created or removed. */
    public function replaceChapters(User $actor, Course $course, array $chapters): Course
    {
        DB::transaction(fn () => $this->syncChapters($course, $chapters));

        $this->audit->record($actor, 'Updated course curriculum', $course->code(), $course->title);

        return $this->load($course->refresh());
    }

    /** Soft delete: enrolments and progress are kept and the course can be restored. */
    public function trash(User $actor, Course $course): void
    {
        $course->delete();
        $this->audit->record($actor, 'Moved course to trash', $course->code(), $course->title);
    }

    public function restore(User $actor, Course $course): Course
    {
        if (! $course->trashed()) {
            return $this->load($course);
        }
        if (Course::query()->where('title', $course->title)->exists()) {
            throw ValidationException::withMessages(['title' => 'A live course with this title exists. Rename it first.']);
        }

        $course->restore();
        $this->audit->record($actor, 'Restored course', $course->code(), $course->title);

        return $this->load($course);
    }

    public function purge(User $actor, Course $course): void
    {
        if (! $course->trashed()) {
            throw ValidationException::withMessages(['course' => 'Move the course to the trash before deleting it permanently.']);
        }

        $code = $course->code();
        $title = $course->title;
        $course->forceDelete(); // chapters and lessons cascade
        $this->audit->record($actor, 'Permanently deleted course', $code, $title);
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    /** @param array<int, array<string, mixed>> $chapters */
    private function syncChapters(Course $course, array $chapters): void
    {
        $existing = $course->chapters()->with('lessons:id,course_chapter_id')->get()->keyBy('id');
        $keptChapters = [];

        foreach (array_values($chapters) as $position => $row) {
            /** @var CourseChapter $chapter */
            $chapter = $existing->get($row['id'] ?? null)
                ?? new CourseChapter(['course_id' => $course->id]);
            $chapter->fill([
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'status' => $row['status'] ?? 'Published',
                'position' => $position,
            ])->save();
            $keptChapters[] = $chapter->id;

            $ownLessons = $chapter->exists ? $chapter->lessons->keyBy('id') : collect();
            $keptLessons = [];
            foreach (array_values($row['lessons'] ?? []) as $lessonPosition => $lesson) {
                $model = $ownLessons->get($lesson['id'] ?? null) ?? $chapter->lessons()->make();
                $model->fill([
                    'name' => $lesson['name'],
                    'duration_minutes' => $lesson['duration_minutes'],
                    'host' => $lesson['host'],
                    'url' => $lesson['url'] ?? null,
                    'content' => $lesson['content'] ?? null,
                    'privacy' => $lesson['privacy'],
                    'description' => $lesson['description'] ?? null,
                    'file_name' => $lesson['file_name'] ?? null,
                    'file_size' => $lesson['file_size'] ?? null,
                    'position' => $lessonPosition,
                ])->save();
                $keptLessons[] = $model->id;
            }
            $chapter->lessons()->whereNotIn('id', $keptLessons)->delete();
        }

        $course->chapters()->whereNotIn('id', $keptChapters)->delete();
    }
}
