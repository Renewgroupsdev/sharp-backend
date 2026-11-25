<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\CourseStatus;
use App\Enums\LessonPrivacy;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseChapter;
use App\Models\CourseLesson;
use App\Models\LessonProgress;
use App\Models\ModuleUnlock;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sequential unlocking. A module (a published chapter) opens when the one before it is finished: every lesson done and
 * every published quiz of that module passed. The first module is always open. Inside a module the lessons open one after
 * the other: a lesson opens when the lesson before it is completed. A mentor or admin can open a module early
 * for one student, with a reason. The rule is enforced on the server wherever a locked lesson or quiz is touched.
 */
class ModuleGateService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The course's modules in order with this student's state.
     *
     * @return list<array{chapter_id: int, name: string, position: int, locked: bool, reason: ?string, complete: bool, unlocked_early: bool, lessons_done: int, lessons_total: int, lessons: list<array{id: int, locked: bool, reason: ?string}>, quizzes: list<array<string, mixed>>}>
     */
    public function modules(User $student, Course $course): array
    {
        $chapters = $course->chapters()->where('status', 'Published')->with('lessons:id,course_chapter_id,privacy,name')->get();
        if ($chapters->isEmpty()) {
            return [];
        }

        $lessonIds = $chapters->flatMap(fn (CourseChapter $c) => $this->visibleLessons($c)->pluck('id'));
        $done = LessonProgress::where('user_id', $student->id)->whereIn('course_lesson_id', $lessonIds)->whereNotNull('completed_at')->pluck('course_lesson_id')->flip();

        $quizzes = Assessment::where('course', $course->title)->where('type', AssessmentType::Quiz->value)
            ->where('status', AssessmentStatus::Published->value)->get(['id', 'title', 'module', 'min_percent']);
        $results = QuizResult::where('student_id', $student->id)->whereIn('assessment_id', $quizzes->pluck('id'))->get()->keyBy('assessment_id');
        $unlocked = ModuleUnlock::where('student_id', $student->id)->whereIn('course_chapter_id', $chapters->pluck('id'))->pluck('course_chapter_id')->flip();

        $out = [];
        foreach ($chapters->values() as $i => $chapter) {
            $lessons = $this->visibleLessons($chapter);
            $mine = $quizzes->where('module', $chapter->name)->map(function (Assessment $a) use ($results) {
                $best = $results->get($a->id)?->result_percent;

                return ['id' => $a->id, 'title' => $a->title, 'best_percent' => $best, 'min_percent' => $a->min_percent, 'passed' => $best !== null && $best >= $a->min_percent];
            })->values()->all();

            $doneCount = $lessons->filter(fn ($l) => $done->has($l->id))->count();
            $complete = $doneCount >= $lessons->count() && collect($mine)->every('passed');
            $early = $unlocked->has($chapter->id);
            $previous = $out[$i - 1] ?? null;
            $locked = $previous !== null && ! $previous['complete'] && ! $early;

            $out[] = [
                'chapter_id' => $chapter->id,
                'name' => $chapter->name,
                'position' => $i,
                'locked' => $locked,
                'reason' => $locked ? $this->reasonFor($previous) : null,
                'complete' => $complete,
                'unlocked_early' => $early,
                'lessons_done' => $doneCount,
                'lessons_total' => $lessons->count(),
                'lessons' => $this->lessonStates($lessons, $done),
                'quizzes' => $mine,
            ];
        }

        return $out;
    }

    /**
     * One entry per lesson in learning order: a lesson is locked until the lesson before it is completed.
     *
     * @param  Collection<int, CourseLesson>  $lessons
     * @param  Collection<int, int>  $done  completed lesson ids as keys
     * @return list<array{id: int, locked: bool, reason: ?string}>
     */
    private function lessonStates(Collection $lessons, Collection $done): array
    {
        $out = [];
        $previous = null;
        foreach ($lessons->values() as $lesson) {
            $locked = $previous !== null && ! $done->has($previous->id);
            $out[] = ['id' => $lesson->id, 'locked' => $locked, 'reason' => $locked ? "Complete “{$previous->name}” first to open this lesson." : null];
            $previous = $lesson;
        }

        return $out;
    }

    /** @return Collection<int, CourseLesson> */
    private function visibleLessons(CourseChapter $chapter): Collection
    {
        return $chapter->lessons->filter(fn (CourseLesson $l) => $l->privacy !== LessonPrivacy::Private);
    }

    /** @param  array<string, mixed>  $previous */
    private function reasonFor(array $previous): string
    {
        $needs = [];
        if ($previous['lessons_done'] < $previous['lessons_total']) {
            $needs[] = 'complete all its lessons';
        }
        if (collect($previous['quizzes'])->contains('passed', false)) {
            $needs[] = 'pass its quiz';
        }

        return "Finish “{$previous['name']}” first: ".(implode(' and ', $needs) ?: 'complete it').'. A mentor can also unlock it.';
    }

    /** A lesson in a locked module cannot be opened or completed. */
    public function assertLessonOpen(User $student, CourseLesson $lesson): void
    {
        $course = $lesson->chapter->course;
        $row = collect($this->modules($student, $course))->firstWhere('chapter_id', $lesson->course_chapter_id);

        abort_if($row && $row['locked'], 403, $row['reason'] ?? 'This module is locked.');

        $state = collect($row['lessons'] ?? [])->firstWhere('id', $lesson->id);
        abort_if($state && $state['locked'], 403, $state['reason'] ?? 'Complete the lesson before this one first.');
    }

    /** A quiz of a locked module cannot be started. */
    public function assertQuizOpen(User $student, Assessment $quiz): void
    {
        abort_if(($reason = $this->lockedQuizzes($student, collect([$quiz]))[$quiz->id] ?? null) !== null, 403, $reason);
    }

    /**
     * Which of the given assessments sit in a locked module, with the reason.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @return array<int, string> assessment id => reason
     */
    public function lockedQuizzes(User $student, Collection $assessments): array
    {
        $locked = [];
        foreach ($assessments->where('type', AssessmentType::Quiz)->groupBy('course') as $title => $group) {
            $course = Course::where('title', $title)->where('status', CourseStatus::Published->value)->first();
            if (! $course) {
                continue;
            }
            $modules = collect($this->modules($student, $course))->keyBy('name');
            foreach ($group as $quiz) {
                $row = $modules->get($quiz->module);
                if ($row && $row['locked']) {
                    $locked[$quiz->id] = $row['reason'];
                }
            }
        }

        return $locked;
    }

    /* ---------- mentor / admin: open a module early ---------- */

    public function unlock(User $actor, User $student, CourseChapter $chapter, string $reason): ModuleUnlock
    {
        $this->assertCanManage($actor, $student);
        $course = $chapter->course;

        $enrolled = $course->batches()->whereKey($student->batch_id ?? 0)->exists();
        if (! $enrolled || $chapter->status !== 'Published') {
            throw ValidationException::withMessages(['chapter_id' => 'That module is not part of this student’s courses.']);
        }
        $row = collect($this->modules($student, $course))->firstWhere('chapter_id', $chapter->id);
        if (! $row || ! $row['locked']) {
            throw ValidationException::withMessages(['chapter_id' => 'That module is already open for the student.']);
        }

        $unlock = ModuleUnlock::create(['student_id' => $student->id, 'course_chapter_id' => $chapter->id, 'unlocked_by' => $actor->id, 'reason' => $reason]);

        $this->notifications->notify('module_unlocked', $student->id, ['module' => $chapter->name, 'course' => $course->title], ['course_id' => $course->id, 'url' => "/app/learning/{$course->id}"]);
        $this->audit->record($actor, 'Unlocked a module early', $student->public_id, "{$course->title} · {$chapter->name} — {$reason}");

        return $this->loaded($unlock);
    }

    /** Takes the early unlock back; the module is locked again unless the student has earned it. */
    public function revoke(User $actor, ModuleUnlock $unlock): void
    {
        $unlock->loadMissing('student', 'chapter.course');
        $this->assertCanManage($actor, $unlock->student);

        $this->audit->record($actor, 'Revoked an early module unlock', $unlock->student->public_id, "{$unlock->chapter->course->title} · {$unlock->chapter->name}");
        $unlock->delete();
    }

    /**
     * @param  array{student_id?: string, course_id?: int}  $filters
     * @return Collection<int, ModuleUnlock>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        return ModuleUnlock::query()->with(['student:id,public_id,name,batch_id', 'chapter:id,course_id,name', 'chapter.course:id,title', 'unlocker:id,name'])
            ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('batch_id', $actor->batch_id ?? 0)))
            ->when(! empty($filters['student_id']), fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('public_id', $filters['student_id'])))
            ->when(! empty($filters['course_id']), fn (Builder $q) => $q->whereHas('chapter', fn (Builder $c) => $c->where('course_id', $filters['course_id'])))
            ->orderByDesc('id')->limit(500)->get();
    }

    /**
     * Every module of every course the student takes, with its lock state: the mentor's "what can I unlock" screen.
     *
     * @return list<array{course: array{id: int, title: string}, modules: list<array<string, mixed>>}>
     */
    public function overview(User $actor, User $student): array
    {
        $this->assertCanManage($actor, $student);

        return Course::query()->where('status', CourseStatus::Published->value)
            ->whereHas('batches', fn (Builder $b) => $b->where('batches.id', $student->batch_id ?? 0))
            ->orderBy('title')->get()
            ->map(fn (Course $c) => ['course' => ['id' => $c->id, 'title' => $c->title], 'modules' => $this->modules($student, $c)])
            ->values()->all();
    }

    private function assertCanManage(User $actor, User $student): void
    {
        abort_if($actor->role === Role::Mentor && $student->batch_id !== $actor->batch_id, 403, 'You can only manage students of your own batch.');
    }

    private function loaded(ModuleUnlock $unlock): ModuleUnlock
    {
        return $unlock->load(['student:id,public_id,name,batch_id', 'chapter:id,course_id,name', 'chapter.course:id,title', 'unlocker:id,name']);
    }
}
