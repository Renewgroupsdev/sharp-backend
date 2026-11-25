<?php

namespace App\Services;

use App\Enums\CourseStatus;
use App\Enums\LearningStatus;
use App\Enums\LessonPrivacy;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\LearningProgress;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lesson-level learning progress. A student is enrolled in every published course mapped to their batch; the
 * lessons that count are the ones learners can see (Published modules, non-private lessons). Completing a lesson
 * recomputes the course percentage stored in learning_progress, which the admin Learning list reads.
 */
class LessonProgressService
{
    public function __construct(private readonly CertificateService $certificates, private readonly ModuleGateService $gate) {}

    /** Lessons a learner sees and is measured on. */
    private function lessons(int $courseId): Builder
    {
        return CourseLesson::query()
            ->join('course_chapters as ch', 'ch.id', '=', 'course_lessons.course_chapter_id')
            ->where('ch.course_id', $courseId)
            ->where('ch.status', 'Published')
            ->where('course_lessons.privacy', '<>', LessonPrivacy::Private->value)
            ->select('course_lessons.*');
    }

    /** How many lessons count towards a course's progress. */
    public function totalLessons(int $courseId): int
    {
        return $this->lessons($courseId)->count();
    }

    private function enrolledCourses(User $student): Builder
    {
        return Course::query()
            ->where('status', CourseStatus::Published->value)
            ->whereHas('batches', fn (Builder $b) => $b->where('batches.id', $student->batch_id ?? 0));
    }

    /** @return array{lessons_total: int, lessons_done: int, progress: int, status: string, last_accessed: ?string} */
    public function summary(User $student, int $courseId): array
    {
        $ids = $this->lessons($courseId)->pluck('course_lessons.id');
        $done = LessonProgress::where('user_id', $student->id)->whereIn('course_lesson_id', $ids)->whereNotNull('completed_at')->count();
        $progress = $ids->count() ? (int) round($done / $ids->count() * 100) : 0;
        $last = LearningProgress::where(['user_id' => $student->id, 'course_id' => $courseId])->value('last_accessed_at');

        return [
            'lessons_total' => $ids->count(),
            'lessons_done' => $done,
            'progress' => $progress,
            'status' => LearningStatus::fromProgress($progress)->value,
            'last_accessed' => $last ? Carbon::parse($last)->toDateString() : null,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function courses(User $student): Collection
    {
        return $this->enrolledCourses($student)->with('category:id,name')->orderBy('title')->get()->map(fn (Course $c) => [
            'id' => $c->id,
            'code' => $c->code(),
            'title' => $c->title,
            'category' => $c->category?->name,
            'hours' => $c->hours,
            'mentors' => $c->mentors ?? [],
            'level' => $c->level,
            'mode' => $c->mode,
            'thumbnail' => $c->thumbnail,
        ] + $this->summary($student, $c->id));
    }

    /** @return array{course: Course, progress: array, completed_lesson_ids: list<int>, modules: list<array<string, mixed>>} */
    public function detail(User $student, Course $course): array
    {
        abort_unless($this->enrolledCourses($student)->whereKey($course->id)->exists(), 404, 'Course not found.');

        return [
            'course' => $course->load(['category:id,name', 'batches:id,name', 'chapters.lessons']),
            'progress' => $this->summary($student, $course->id),
            'completed_lesson_ids' => $this->completedIds($student, $course->id),
            'modules' => $this->gate->modules($student, $course),
        ];
    }

    /** First open starts the lesson; every open stamps the course's last-accessed time. */
    public function open(User $student, CourseLesson $lesson): array
    {
        $courseId = $this->courseOf($student, $lesson);
        $this->gate->assertLessonOpen($student, $lesson);

        LessonProgress::firstOrCreate(['user_id' => $student->id, 'course_lesson_id' => $lesson->id], ['started_at' => now()]);
        LearningProgress::updateOrCreate(['user_id' => $student->id, 'course_id' => $courseId], ['last_accessed_at' => now()]);

        return $this->state($student, $courseId);
    }

    /** Idempotent: completing a lesson twice changes nothing. */
    public function complete(User $student, CourseLesson $lesson): array
    {
        $courseId = $this->courseOf($student, $lesson);
        $this->gate->assertLessonOpen($student, $lesson);

        $row = LessonProgress::firstOrNew(['user_id' => $student->id, 'course_lesson_id' => $lesson->id]);
        $row->started_at ??= now();
        $row->completed_at ??= now();
        $row->save();

        $summary = $this->summary($student, $courseId);
        LearningProgress::updateOrCreate(['user_id' => $student->id, 'course_id' => $courseId], [
            'progress' => $summary['progress'],
            'materials_completed' => $summary['lessons_done'],
            'last_accessed_at' => now(),
        ]);

        // Finishing the course asks for a certificate; an admin issues it.
        if ($summary['progress'] >= 100) {
            $this->certificates->request($student, $courseId);
        }

        return $this->state($student, $courseId);
    }

    /**
     * Per-module breakdown of a student's real lesson completions, for the admin progress report.
     *
     * @return list<array{name: string, done: int, total: int, pct: int, status: string}>
     */
    public function report(string $studentPublicId, int $courseId): array
    {
        $student = User::students()->where('public_id', $studentPublicId)->firstOrFail();
        $course = Course::findOrFail($courseId);
        if (! $course->batches()->whereKey($student->batch_id ?? 0)->exists()) {
            throw ValidationException::withMessages(['student_id' => 'This student is not enrolled in the course.']);
        }

        $done = array_flip($this->completedIds($student, $courseId));

        return $course->chapters()->where('status', 'Published')->with('lessons')->get()->map(function ($chapter) use ($done) {
            $lessons = $chapter->lessons->filter(fn ($l) => $l->privacy !== LessonPrivacy::Private);
            $count = $lessons->filter(fn ($l) => isset($done[$l->id]))->count();
            $pct = $lessons->count() ? (int) round($count / $lessons->count() * 100) : 0;

            return ['name' => $chapter->name, 'done' => $count, 'total' => $lessons->count(), 'pct' => $pct, 'status' => LearningStatus::fromProgress($pct)->value];
        })->values()->all();
    }

    /** @return list<int> */
    private function completedIds(User $student, int $courseId): array
    {
        $ids = $this->lessons($courseId)->pluck('course_lessons.id');

        return LessonProgress::where('user_id', $student->id)->whereIn('course_lesson_id', $ids)->whereNotNull('completed_at')
            ->pluck('course_lesson_id')->map(fn ($id) => (int) $id)->all();
    }

    private function courseOf(User $student, CourseLesson $lesson): int
    {
        $courseId = (int) $lesson->chapter()->value('course_id');
        $visible = $this->enrolledCourses($student)->whereKey($courseId)->exists()
            && $this->lessons($courseId)->where('course_lessons.id', $lesson->id)->exists();
        abort_unless($visible, 404, 'Lesson not found.');

        return $courseId;
    }

    private function state(User $student, int $courseId): array
    {
        return ['progress' => $this->summary($student, $courseId), 'completed_lesson_ids' => $this->completedIds($student, $courseId)];
    }
}
