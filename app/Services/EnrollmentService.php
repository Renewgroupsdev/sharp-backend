<?php

namespace App\Services;

use App\Enums\EnrollmentEventType;
use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\EnrollmentEvent;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enrollment management. An enrollment is a course run for a batch (a batch_course row): every student of the batch is
 * enrolled, and the batch's mentors teach it. There is no table of its own: the list is derived, with the student and
 * mentor counts as subselects, and the people behind a count are loaded only when asked for.
 *
 * Every change to the mapping, from this screen or from the course / batch forms, goes through recordChanges(), which
 * writes the history and notifies the students and mentors involved.
 */
class EnrollmentService
{
    public const MAX_IMPORT_ROWS = 1000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly LessonProgressService $lessons,
    ) {}

    private function query(User $actor): Builder
    {
        return DB::table('batch_course as bc')
            ->join('courses as c', fn ($j) => $j->on('c.id', '=', 'bc.course_id')->whereNull('c.deleted_at'))
            ->join('batches as b', 'b.id', '=', 'bc.batch_id')
            ->select([
                'bc.id', 'c.id as course_id', 'c.title as course', 'b.id as batch_id', 'b.name as batch',
                'b.start_date', 'b.end_date', 'b.status as batch_status',
                DB::raw("(SELECT COUNT(*) FROM users s WHERE s.batch_id = b.id AND s.role = '".Role::Student->value."') AS students_count"),
                // The mentors of a course run are the ones picked on the course (names, as the course form stores them).
                DB::raw('(SELECT COUNT(*) FROM users m WHERE '.self::courseMentorSql().') AS mentors_count'),
            ])
            // A mentor only sees the enrollments of their own batch.
            ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->where('b.id', $actor->batch_id ?? 0))
            ->orderByRaw("
                CASE
                    WHEN LOWER(b.status) = 'ongoing' THEN 1
                    WHEN LOWER(b.status) = 'upcoming' THEN 2
                    WHEN LOWER(b.status) = 'completed' THEN 3
                    ELSE 4
                END ASC
            ")
            ->orderByDesc('b.start_date')->orderBy('c.title');
    }

    /**
     * @param  array{q?: string, course_id?: int, batch_id?: int, status?: string}  $filters
     * @return Collection<int, object>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $status = ! empty($filters['status']) ? EnrollmentStatus::from($filters['status']) : null;

        return $this->query($actor)
            ->when(! empty($filters['q']), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereLike('c.title', "%{$filters['q']}%")->orWhereLike('b.name', "%{$filters['q']}%")))
            ->when(! empty($filters['course_id']), fn (Builder $q) => $q->where('c.id', $filters['course_id']))
            ->when(! empty($filters['batch_id']), fn (Builder $q) => $q->where('b.id', $filters['batch_id']))
            ->when($status, fn (Builder $q) => $status->constrain($q))
            ->get();
    }

    public function find(User $actor, int $id): object
    {
        return $this->query($actor)->where('bc.id', $id)->first() ?? abort(404, 'Enrollment not found.');
    }

    /** The mentors teaching the course (Mentor-role users whose names are on the course), as a SQL condition on `users m` and `courses c`. */
    private static function courseMentorSql(): string
    {
        return "m.role = '".Role::Mentor->value."' AND m.name IN (SELECT jsonb_array_elements_text(CASE WHEN jsonb_typeof(c.mentors::jsonb) = 'array' THEN c.mentors::jsonb ELSE '[]'::jsonb END))";
    }

    /** The students of the enrollment's batch and the mentors picked on the course. */
    public function members(User $actor, int $id): array
    {
        $enrollment = $this->find($actor, $id);
        $columns = ['public_id', 'name', 'org', 'mobile', 'email', 'status', 'specialist'];
        $names = json_decode((string) DB::table('courses')->where('id', $enrollment->course_id)->value('mentors'), true);

        return [
            'students' => User::query()->where('batch_id', $enrollment->batch_id)->where('role', Role::Student->value)->orderBy('name')->get($columns),
            'mentors' => User::query()->where('role', Role::Mentor->value)->whereIn('name', is_array($names) ? $names : [])->orderBy('name')->get($columns),
        ];
    }

    /**
     * One enrollment in full: every student's progress, attendance, quiz average and certificate, the mentors, a summary
     * and the history of the course for this batch.
     *
     * @return array{enrollment: object, summary: array, students: Collection, mentors: Collection, history: Collection}
     */
    public function detail(User $actor, int $id): array
    {
        $e = $this->find($actor, $id);
        $total = $this->lessons->totalLessons($e->course_id);

        $students = DB::table('users as u')
            ->leftJoin('learning_progress as lp', fn ($j) => $j->on('lp.user_id', '=', 'u.id')->where('lp.course_id', $e->course_id))
            ->leftJoin('certificates as ce', fn ($j) => $j->on('ce.user_id', '=', 'u.id')->where('ce.course_id', $e->course_id))
            ->where('u.batch_id', $e->batch_id)->where('u.role', Role::Student->value)
            ->select([
                'u.public_id as student_id', 'u.name', 'u.org', 'u.status',
                DB::raw('COALESCE(lp.progress, 0) as progress'),
                DB::raw('COALESCE(lp.materials_completed, 0) as lessons_done'),
                'lp.last_accessed_at',
                'ce.id as certificate_id', 'ce.status as certificate_status', 'ce.number as certificate_number',
                DB::raw("(SELECT ROUND(AVG(CASE WHEN a.status <> 'Absent' THEN 100 ELSE 0 END)) FROM attendance a WHERE a.student_id = u.id) AS attendance_rate"),
            ])
            ->selectRaw('(SELECT ROUND(AVG(q.result_percent)) FROM quiz_results q JOIN assessments s ON s.id = q.assessment_id WHERE q.student_id = u.id AND s.course = ?) AS quiz_avg', [$e->course])
            ->orderBy('u.name')->get();

        $avg = fn (string $key) => $students->whereNotNull($key)->count() ? (int) round($students->whereNotNull($key)->avg($key)) : null;

        return [
            'enrollment' => $e,
            'lessons_total' => $total,
            'summary' => [
                'students' => $students->count(),
                'completed' => $students->where('progress', '>=', 100)->count(),
                'avg_progress' => $students->count() ? (int) round($students->avg('progress')) : 0,
                'avg_attendance' => $avg('attendance_rate'),
                'avg_quiz' => $avg('quiz_avg'),
                'certificates_issued' => $students->where('certificate_status', 'Issued')->count(),
                'certificates_pending' => $students->where('certificate_status', 'Pending')->count(),
            ],
            'students' => $students,
            'mentors' => $this->members($actor, $id)['mentors'],
            'history' => $this->history($actor, ['course_id' => $e->course_id, 'batch_id' => $e->batch_id], 50),
        ];
    }

    /**
     * What happened to the mapping, newest first. Removed enrollments stay in the trail.
     *
     * @param  array{course_id?: int, batch_id?: int}  $filters
     * @return Collection<int, object>
     */
    public function history(User $actor, array $filters = [], int $limit = 300): Collection
    {
        return DB::table('enrollment_events as ev')
            ->join('courses as c', 'c.id', '=', 'ev.course_id')
            ->join('batches as b', 'b.id', '=', 'ev.batch_id')
            ->leftJoin('users as a', 'a.id', '=', 'ev.actor_id')
            ->select(['ev.id', 'ev.course_id', 'c.title as course', 'ev.batch_id', 'b.name as batch', 'ev.event', 'ev.source', 'ev.note', 'a.name as actor', 'ev.created_at'])
            ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->where('ev.batch_id', $actor->batch_id ?? 0))
            ->when(! empty($filters['course_id']), fn (Builder $q) => $q->where('ev.course_id', $filters['course_id']))
            ->when(! empty($filters['batch_id']), fn (Builder $q) => $q->where('ev.batch_id', $filters['batch_id']))
            ->orderByDesc('ev.id')->limit($limit)->get();
    }

    /**
     * Writes the history and notifies the people involved after the mapping of one course changed. Used by this
     * service and by the course and batch forms, so every path leaves the same trail.
     *
     * @param  list<int>  $attached  batch ids that now take the course
     * @param  list<int>  $detached  batch ids that no longer take it
     */
    public function recordChanges(User $actor, int $courseId, array $attached, array $detached, string $source = 'manual'): void
    {
        if (! $attached && ! $detached) {
            return;
        }
        $course = Course::withTrashed()->find($courseId);
        if (! $course) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ([[EnrollmentEventType::Enrolled, $attached], [EnrollmentEventType::Removed, $detached]] as [$type, $ids]) {
            foreach ($ids as $batchId) {
                $rows[] = ['course_id' => $courseId, 'batch_id' => $batchId, 'event' => $type->value, 'source' => $source, 'actor_id' => $actor->id, 'note' => null, 'created_at' => $now];
            }
        }
        EnrollmentEvent::insert($rows);

        $batches = Batch::whereIn('id', [...$attached, ...$detached])->get()->keyBy('id');
        foreach ($attached as $batchId) {
            $batch = $batches[$batchId];
            $when = $batch->start_date ? ' Starts '.$batch->start_date->format('d M Y').'.' : '';
            $data = ['course_id' => $courseId, 'batch_id' => $batchId, 'url' => '/app/learning'];
            $vars = ['course' => $course->title, 'batch' => $batch->name, 'when' => $when];
            $this->notifications->notifyBatch('course_added_student', $batchId, [Role::Student], $vars, $data);
            $this->notifications->notifyBatch('course_added_mentor', $batchId, [Role::Mentor], $vars, $data + ['url' => '/app/enrollment']);
        }
        foreach ($detached as $batchId) {
            $this->notifications->notifyBatch('course_removed', $batchId, [Role::Student, Role::Mentor], ['course' => $course->title, 'batch' => $batches[$batchId]->name], ['course_id' => $courseId, 'batch_id' => $batchId]);
        }

        if ($attached) {
            $this->audit->record($actor, 'Enrolled batches in a course', $course->code(), count($attached).' batch(es) · '.$source);
        }
        if ($detached) {
            $this->audit->record($actor, 'Removed batches from a course', $course->code(), count($detached).' batch(es) · '.$source);
        }
    }

    /**
     * The batch-side form changed which courses a batch takes: record each one as a change of that course.
     *
     * @param  array{attached: list<int>, detached: list<int>}  $changes  the result of sync() on the batch's courses
     */
    public function recordBatchChanges(User $actor, int $batchId, array $changes, string $source): void
    {
        foreach ($changes['attached'] as $courseId) {
            $this->recordChanges($actor, (int) $courseId, [$batchId], [], $source);
        }
        foreach ($changes['detached'] as $courseId) {
            $this->recordChanges($actor, (int) $courseId, [], [$batchId], $source);
        }
    }

    /**
     * Enrol the batches in each course; pairs that already exist are left alone.
     *
     * @param  list<int>  $courseIds
     * @param  list<int>  $batchIds
     * @return int how many new enrollments were created
     */
    public function enroll(User $actor, array $courseIds, array $batchIds, string $source = 'manual'): int
    {
        return DB::transaction(function () use ($actor, $courseIds, $batchIds, $source) {
            $created = 0;
            foreach (Course::whereIn('id', $courseIds)->get() as $course) {
                $attached = $course->batches()->syncWithoutDetaching($batchIds)['attached'];
                $this->recordChanges($actor, $course->id, $attached, [], $source);
                $created += count($attached);
            }

            return $created;
        });
    }

    /** The enrollments of the given batches in the given courses, for the response after a write. */
    public function pairs(User $actor, array $courseIds, array $batchIds): Collection
    {
        return $this->query($actor)->whereIn('c.id', $courseIds)->whereIn('b.id', $batchIds)->get();
    }

    /**
     * Give the target batches every course the selected enrollments run.
     *
     * @param  list<int>  $enrollmentIds
     * @param  list<int>  $batchIds
     */
    public function copy(User $actor, array $enrollmentIds, array $batchIds): int
    {
        $courseIds = DB::table('batch_course')->whereIn('id', $enrollmentIds)->pluck('course_id')->unique()->values()->all();

        return $this->enroll($actor, $courseIds, $batchIds, 'copy');
    }

    /**
     * Remove several enrollments at once.
     *
     * @param  list<int>  $ids
     */
    public function removeMany(User $actor, array $ids): int
    {
        return DB::transaction(function () use ($actor, $ids) {
            $removed = 0;
            foreach (DB::table('batch_course')->whereIn('id', $ids)->get(['course_id', 'batch_id'])->groupBy('course_id') as $courseId => $pairs) {
                $batchIds = $pairs->pluck('batch_id')->map(fn ($b) => (int) $b)->all();
                Course::withTrashed()->find($courseId)?->batches()->detach($batchIds);
                $this->recordChanges($actor, (int) $courseId, [], $batchIds, 'bulk');
                $removed += count($batchIds);
            }

            return $removed;
        });
    }

    public function remove(User $actor, int $id): void
    {
        $this->find($actor, $id);
        $this->removeMany($actor, [$id]);
    }

    /**
     * Enrol from a sheet of (course, batch) rows. A course is matched by its code (CRS-007) or title, a batch by name.
     * With $dryRun nothing is written, so the screen can show what would happen first.
     *
     * @param  list<array{course?: string, batch?: string}>  $rows
     * @return array{dry_run: bool, summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function import(User $actor, array $rows, bool $dryRun): array
    {
        $courses = Course::get(['id', 'title']);
        $byCourse = $courses->flatMap(fn ($c) => [mb_strtolower($c->title) => $c->id, mb_strtolower($c->code()) => $c->id]);
        $byBatch = Batch::get(['id', 'name'])->mapWithKeys(fn ($b) => [mb_strtolower($b->name) => $b->id]);
        $taken = DB::table('batch_course')->get(['course_id', 'batch_id'])->mapWithKeys(fn ($r) => ["{$r->course_id}:{$r->batch_id}" => true]);

        $results = [];
        $toAttach = [];
        foreach (array_values($rows) as $i => $row) {
            $course = trim((string) ($row['course'] ?? ''));
            $batch = trim((string) ($row['batch'] ?? ''));
            $courseId = $byCourse[mb_strtolower($course)] ?? null;
            $batchId = $byBatch[mb_strtolower($batch)] ?? null;

            [$status, $message] = match (true) {
                $course === '' || $batch === '' => ['error', 'Course and batch are both required'],
                $courseId === null => ['error', "Unknown course “{$course}”"],
                $batchId === null => ['error', "Unknown batch “{$batch}”"],
                isset($taken["{$courseId}:{$batchId}"]) => ['exists', 'Already enrolled'],
                default => ['ok', 'Will be enrolled'],
            };
            if ($status === 'ok') {
                $taken["{$courseId}:{$batchId}"] = true; // a repeated row in the same file is "already enrolled"
                $toAttach[$courseId][] = $batchId;
            }
            $results[] = ['line' => $i + 1, 'course' => $course, 'batch' => $batch, 'status' => $status, 'message' => $message];
        }

        if (! $dryRun && $toAttach) {
            DB::transaction(function () use ($actor, $toAttach) {
                foreach ($toAttach as $courseId => $batchIds) {
                    $this->enroll($actor, [(int) $courseId], $batchIds, 'import');
                }
            });
        }

        $count = fn (string $s) => count(array_filter($results, fn ($r) => $r['status'] === $s));

        return ['dry_run' => $dryRun, 'summary' => ['ok' => $count('ok'), 'exists' => $count('exists'), 'error' => $count('error')], 'rows' => $results];
    }

    /** Creates a Pending certificate for every student of the enrollment who finished the course and has none yet. */
    public function generateCertificates(User $actor, int $id): int
    {
        $e = $this->find($actor, $id);

        $done = DB::table('users as u')
            ->join('learning_progress as lp', fn ($j) => $j->on('lp.user_id', '=', 'u.id')->where('lp.course_id', $e->course_id)->where('lp.progress', '>=', 100))
            ->where('u.batch_id', $e->batch_id)->where('u.role', Role::Student->value)
            ->pluck('u.id');

        $made = 0;
        foreach ($done as $userId) {
            $made += Certificate::firstOrCreate(['user_id' => $userId, 'course_id' => $e->course_id])->wasRecentlyCreated ? 1 : 0;
        }
        if ($made) {
            $this->audit->record($actor, 'Generated certificates', 'CRS-'.str_pad((string) $e->course_id, 3, '0', STR_PAD_LEFT), "{$made} for {$e->batch}");
        }

        return $made;
    }
}
