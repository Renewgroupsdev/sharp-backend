<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\AttendanceRequestStatus;
use App\Enums\AttendanceStatus;
use App\Enums\BatchStatus;
use App\Enums\CertificateStatus;
use App\Enums\CourseStatus;
use App\Enums\Role;
use App\Enums\SubmissionStatus;
use App\Enums\UserStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything on the Super Admin analytics dashboard: headline figures plus one block per module (attendance, users,
 * courses, assignments, assessments, quizzes, certificates, community, logs). Each block is shaped for its chart.
 * Percentages are whole numbers; a share with nothing to divide by is 0.
 */
class AnalyticsService
{
    private Carbon $from;

    private Carbon $to;

    private int $months;

    /** @return array<string, mixed> */
    public function report(int $months = 6): array
    {
        $this->months = max(1, min($months, 12));
        $this->to = now();
        $this->from = now()->startOfMonth()->subMonths($this->months - 1);

        return [
            'months' => $this->months,
            'generated_at' => now()->toIso8601String(),
            'kpis' => $this->kpis(),
            'pending_attendance' => $this->pendingAttendance(),
            'attendance' => $this->attendance(),
            'users' => $this->users(),
            'courses' => $this->courses(),
            'assignments' => $this->assignments(),
            'assessments' => $this->assessments(),
            'quiz' => $this->quiz(),
            'certificates' => $this->certificates(),
            'community' => $this->community(),
            'logs' => $this->logs(),
        ];
    }

    private static function pct(int|float $part, int|float $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }

    /** Growth of this period's new rows over the period before it, as a whole percent (0 when there is nothing to compare). */
    private function growth(string $table, string $column = 'created_at', ?callable $scope = null): int
    {
        $span = $this->months;
        $count = function (Carbon $a, Carbon $b) use ($table, $column, $scope) {
            $q = DB::table($table)->where($column, '>=', $a)->where($column, '<', $b);

            return $scope ? $scope($q)->count() : $q->count();
        };
        $now = $count($this->from, $this->to->copy()->addSecond());
        $before = $count($this->from->copy()->subMonths($span), $this->from);

        return $before > 0 ? (int) round(($now - $before) / $before * 100) : ($now > 0 ? 100 : 0);
    }

    /** @return list<array{label: string, key: string}> the months in range, oldest first */
    private function monthSlots(): array
    {
        $slots = [];
        for ($i = 0; $i < $this->months; $i++) {
            $m = $this->from->copy()->addMonths($i);
            $slots[] = ['label' => $m->format('M'), 'key' => $m->format('Y-m')];
        }

        return $slots;
    }

    /** @return array<string, mixed> */
    private function kpis(): array
    {
        $students = DB::table('users')->where('role', Role::Student->value);
        // Course attendance: each student's days attended out of the working days of their course (batch) dates, averaged.
        $summary = new AttendanceSummary();
        $ids = (clone $students)->pluck('id');
        $average = fn (?Carbon $asOf) => ($r = collect($summary->ratesFor($ids, $asOf))) ->isEmpty() ? 0 : (int) round($r->avg());
        $rate = $average($this->to);
        $before = $average($this->from);

        return [
            'students' => (clone $students)->count(),
            'students_growth' => $this->growth('users', 'created_at', fn ($q) => $q->where('role', Role::Student->value)),
            'institutes' => DB::table('colleges')->count(),
            'institutes_active' => DB::table('colleges')->where('status', 'Active')->count(),
            'courses' => DB::table('courses')->whereNull('deleted_at')->where('status', CourseStatus::Published->value)->count(),
            'courses_growth' => $this->growth('courses', 'created_at', fn ($q) => $q->whereNull('deleted_at')),
            'batches' => DB::table('batches')->count(),
            'batches_upcoming' => DB::table('batches')->where('status', BatchStatus::Enrolling->value)->count(),
            'mentors' => DB::table('users')->where('role', Role::Mentor->value)->count(),
            'mentors_growth' => $this->growth('users', 'created_at', fn ($q) => $q->where('role', Role::Mentor->value)),
            'attendance_rate' => $rate,
            'attendance_delta' => $rate - $before,
        ];
    }
    /**
     * The month's calendar of pending attendance, split in two: students who sent a report nobody has decided yet
     * ("requested") and students with neither a report nor a mark ("not marked"). A day with no marks or reports at all
     * (a holiday) is left out, except today.
     *
     * @return array{month: string, days: list<array{date: string, requested: int, not_marked: int, total: int}>}
     */
    private function pendingAttendance(): array
    {
        $first = today()->startOfMonth();
        $active = DB::table('users')->where('role', Role::Student->value)->where('status', UserStatus::Active->value)->pluck('id')->all();
        $total = count($active);
        $activeSet = array_flip($active);

        $marks = DB::table('attendance')->where('att_date', '>=', $first->toDateString())->selectRaw('att_date::date AS d, student_id')->get();
        $reports = DB::table('attendance_requests')->where('att_date', '>=', $first->toDateString())->selectRaw('att_date::date AS d, student_id, status')->get();

        $markedBy = [];
        foreach ($marks as $m) {
            if (isset($activeSet[$m->student_id])) {
                $markedBy[substr((string) $m->d, 0, 10)][$m->student_id] = true;
            }
        }
        $waitingBy = [];
        $touched = $markedBy;
        foreach ($reports as $r) {
            $d = substr((string) $r->d, 0, 10);
            if (! isset($activeSet[$r->student_id])) {
                continue;
            }
            $touched[$d][$r->student_id] = true;
            if ($r->status === AttendanceRequestStatus::Pending->value && ! isset($markedBy[$d][$r->student_id])) {
                $waitingBy[$d][$r->student_id] = true;
            }
        }

        $days = [];
        for ($day = $first->copy(); $day->lte(today()); $day->addDay()) {
            $d = $day->toDateString();
            if (empty($touched[$d]) && ! $day->isToday()) {
                continue;
            }
            $requested = count($waitingBy[$d] ?? []);
            $days[] = ['date' => $d, 'requested' => $requested, 'not_marked' => max(0, $total - count($touched[$d] ?? [])), 'total' => $total];
        }

        return ['month' => $first->format('Y-m'), 'days' => $days];
    }

    private function attendanceRate(Carbon $a, Carbon $b): int
    {
        $row = DB::table('attendance')->where('att_date', '>=', $a->toDateString())->where('att_date', '<', $b->toDateString())
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) AS attended', [AttendanceStatus::Absent->value])->first();

        return self::pct((int) $row->attended, (int) $row->total);
    }

    /** @return array<string, mixed> */
    private function attendance(): array
    {
        // Heatmap: the last five weeks, one row per week (Monday first), a cell per day holding that day's attendance %.
        $start = now()->startOfWeek()->subWeeks(4);
        $days = DB::table('attendance')->where('att_date', '>=', $start->toDateString())
            ->selectRaw('att_date::date AS d, COUNT(*) AS total, SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) AS attended', [AttendanceStatus::Absent->value])
            ->groupBy('d')->get()->keyBy(fn ($r) => substr((string) $r->d, 0, 10));

        $weeks = [];
        for ($w = 0; $w < 5; $w++) {
            $first = $start->copy()->addWeeks($w);
            $cells = [];
            for ($d = 0; $d < 7; $d++) {
                $date = $first->copy()->addDays($d);
                $row = $days->get($date->toDateString());
                $cells[] = $row && $row->total > 0 ? self::pct((int) $row->attended, (int) $row->total) : null;
            }
            $weeks[] = ['label' => $first->format('j M').' – '.$first->copy()->addDays(6)->format('j M'), 'start' => $first->toDateString(), 'cells' => $cells];
        }

        // Course attendance per batch: the average of its students' days attended out of the working days of the course dates.
        $students = DB::table('users')->where('role', Role::Student->value)->whereNotNull('batch_id')->get(['id', 'batch_id']);
        $rates = (new AttendanceSummary())->ratesFor($students->pluck('id'));
        $names = DB::table('batches')->pluck('name', 'id');
        $batches = $students->groupBy('batch_id')->map(function ($group, $batchId) use ($rates, $names) {
            $values = $group->map(fn ($s) => $rates[$s->id] ?? null)->filter(fn ($v) => $v !== null);

            return ['name' => (string) ($names[$batchId] ?? "Batch {$batchId}"), 'rate' => $values->isEmpty() ? 0 : (int) round($values->avg()), 'students' => $group->count()];
        })->sortByDesc('students')->take(6)->values()->all();

        return ['heatmap' => $weeks, 'batches' => $batches];
    }

    /** @return array<string, mixed> */
    private function users(): array
    {
        $labels = [
            Role::Student->value => 'Students', Role::Mentor->value => 'Mentors', Role::Ob->value => 'OBs',
            Role::Admin->value => 'Admins', Role::SuperAdmin->value => 'Super Admins', Role::Trainer->value => 'Trainers',
            Role::Member->value => 'Members', Role::Institution->value => 'Institutions',
        ];
        $counts = DB::table('users')->selectRaw('role, COUNT(*) AS n')->groupBy('role')->pluck('n', 'role');
        $roles = collect($labels)->map(fn ($label, $role) => ['label' => $label, 'value' => (int) ($counts[$role] ?? 0)])
            ->filter(fn ($r) => $r['value'] > 0)->sortByDesc('value')->values()->all();

        $byMonth = DB::table('users')->where('created_at', '>=', $this->from)
            ->selectRaw("to_char(created_at, 'YYYY-MM') AS m, COUNT(*) AS n")->groupBy('m')->pluck('n', 'm');

        return [
            'total' => (int) $counts->sum(),
            'roles' => $roles,
            'registrations' => array_map(fn ($s) => ['label' => $s['label'], 'value' => (int) ($byMonth[$s['key']] ?? 0)], $this->monthSlots()),
        ];
    }

    /** @return array<string, mixed> */
    private function courses(): array
    {
        $progress = DB::table('learning_progress')
            ->join('courses', 'courses.id', '=', 'learning_progress.course_id')->whereNull('courses.deleted_at')
            ->selectRaw('courses.title AS label, COUNT(*) AS enrolled, AVG(learning_progress.progress) AS avg')
            ->groupBy('courses.id', 'courses.title')->orderByDesc('enrolled')->limit(5)->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => (int) round((float) $r->avg)])->all();

        $enrollment = DB::table('learning_progress')
            ->join('courses', 'courses.id', '=', 'learning_progress.course_id')
            ->join('course_categories', 'course_categories.id', '=', 'courses.course_category_id')
            ->selectRaw('course_categories.name AS label, COUNT(*) AS n')
            ->groupBy('course_categories.id', 'course_categories.name')->orderByDesc('n')->limit(4)->get();
        $total = (int) $enrollment->sum('n');

        return [
            'completion' => $progress,
            'enrollment' => $enrollment->map(fn ($r) => ['label' => $r->label, 'value' => self::pct((int) $r->n, $total), 'count' => (int) $r->n])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function assignments(): array
    {
        $assignments = DB::table('assessments')->where('type', AssessmentType::Assignment->value)->where('status', '<>', AssessmentStatus::Draft->value);
        $ids = (clone $assignments)->pluck('due_date', 'id');
        $pastDue = $ids->filter(fn ($due) => $due && Carbon::parse($due)->endOfDay()->isPast())->count();

        $submitted = DB::table('assessment_submissions')->whereIn('assessment_id', $ids->keys())
            ->selectRaw('assessment_id, student_id, MAX(status) AS status')->groupBy('assessment_id', 'student_id');

        // Per batch: how many of the assignments each student owes are submitted / in review / graded / still missing past due.
        $students = DB::table('users')->join('batches', 'batches.id', '=', 'users.batch_id')->where('users.role', Role::Student->value)
            ->selectRaw('batches.name AS batch, users.id AS id')->get()->groupBy('batch');
        $subs = DB::query()->fromSub($submitted, 's')->join('users', 'users.id', '=', 's.student_id')->join('batches', 'batches.id', '=', 'users.batch_id')
            ->selectRaw('batches.name AS batch, s.status AS status, COUNT(*) AS n')->groupBy('batches.name', 's.status')->get()->groupBy('batch');

        $byBatch = [];
        foreach ($students as $batch => $rows) {
            $by = ($subs[$batch] ?? collect())->pluck('n', 'status');
            $graded = (int) ($by[SubmissionStatus::Graded->value] ?? 0) + (int) ($by[SubmissionStatus::Returned->value] ?? 0);
            $review = (int) ($by[SubmissionStatus::UnderReview->value] ?? 0);
            $done = (int) ($by[SubmissionStatus::Submitted->value] ?? 0);
            $overdue = max(0, $rows->count() * $pastDue - ($graded + $review + $done));
            $byBatch[] = ['name' => (string) $batch, 'submitted' => $done, 'pending' => $review, 'evaluated' => $graded, 'overdue' => $overdue];
        }
        usort($byBatch, fn ($a, $b) => array_sum([$b['submitted'], $b['pending'], $b['evaluated'], $b['overdue']]) <=> array_sum([$a['submitted'], $a['pending'], $a['evaluated'], $a['overdue']]));

        $studentCount = DB::table('users')->where('role', Role::Student->value)->count();
        $totalSubs = DB::query()->fromSub($submitted, 's')->count();
        $reviewed = DB::query()->fromSub($submitted, 's')->whereIn('status', [SubmissionStatus::UnderReview->value, SubmissionStatus::Graded->value, SubmissionStatus::Returned->value])->count();
        $graded = DB::query()->fromSub($submitted, 's')->whereIn('status', [SubmissionStatus::Graded->value, SubmissionStatus::Returned->value])->count();

        return [
            'by_batch' => array_slice($byBatch, 0, 6),
            'funnel' => [
                ['label' => 'Assigned', 'value' => max($studentCount * $ids->count(), $totalSubs)],
                ['label' => 'Submitted', 'value' => $totalSubs],
                ['label' => 'In review', 'value' => $reviewed],
                ['label' => 'Evaluated', 'value' => $graded],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function assessments(): array
    {
        // Score distribution: every submitted quiz attempt plus every graded assignment / assessment, as a % of its marks.
        $scores = DB::table('quiz_attempts')->whereNotNull('submitted_at')->selectRaw('result_percent AS p')
            ->unionAll(
                DB::table('assessment_submissions')->join('assessments', 'assessments.id', '=', 'assessment_submissions.assessment_id')
                    ->where('assessment_submissions.status', SubmissionStatus::Graded->value)->where('assessments.max_marks', '>', 0)
                    ->selectRaw('LEAST(100, ROUND(assessment_submissions.marks_obtained / assessments.max_marks * 100)) AS p')
            );
        $buckets = [['0–40', 0, 40], ['41–60', 41, 60], ['61–80', 61, 80], ['81–100', 81, 100]];
        $dist = [];
        foreach ($buckets as [$label, $lo, $hi]) {
            $dist[] = ['label' => $label, 'value' => DB::query()->fromSub($scores, 'x')->whereBetween('p', [$lo, $hi])->count()];
        }

        $byMonth = DB::table('quiz_attempts')->whereNotNull('submitted_at')->where('submitted_at', '>=', $this->from)
            ->selectRaw("to_char(submitted_at, 'YYYY-MM') AS m, SUM(CASE WHEN passed THEN 1 ELSE 0 END) AS pass, SUM(CASE WHEN passed THEN 0 ELSE 1 END) AS fail, AVG(result_percent) AS avg")
            ->groupBy('m')->get()->keyBy('m');
        $trend = array_map(function ($s) use ($byMonth) {
            $r = $byMonth->get($s['key']);

            return ['label' => $s['label'], 'pass' => (int) ($r->pass ?? 0), 'fail' => (int) ($r->fail ?? 0), 'avg' => $r ? (int) round((float) $r->avg) : null];
        }, $this->monthSlots());

        return ['distribution' => $dist, 'trend' => $trend];
    }

    /** @return array<string, mixed> */
    private function quiz(): array
    {
        $topics = DB::table('quiz_attempts')->join('assessments', 'assessments.id', '=', 'quiz_attempts.assessment_id')
            ->whereNotNull('quiz_attempts.submitted_at')
            ->whereRaw('NOT ('.\App\Models\QuizAttempt::TIMED_OUT_SQL.')') // timed-out attempts carry no real score
            ->selectRaw("COALESCE(NULLIF(assessments.topic, ''), NULLIF(assessments.module, ''), assessments.course) AS label, AVG(quiz_attempts.result_percent) AS avg, COUNT(*) AS n")
            ->groupBy('label')->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) round((float) $r->avg)])->all();

        // One row per (student, quiz) pair: every active student in a batch that takes the quiz's published course,
        // for every published quiz. Passed = some submitted attempt passed; Not passed = they attempted (a timed-out attempt
        // counts, it is a used attempt with no marks) but never passed; Not attempted = no submitted attempt at all.
        $row = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE s.passed) AS passed,
                COUNT(*) FILTER (WHERE s.tried > 0 AND NOT s.passed) AS not_passed,
                COUNT(*) FILTER (WHERE s.tried = 0) AS not_attempted
            FROM (
                SELECT p.aid, p.sid,
                    COUNT(qa.id) FILTER (WHERE qa.submitted_at IS NOT NULL) AS tried,
                    COALESCE(BOOL_OR(qa.passed) FILTER (WHERE qa.submitted_at IS NOT NULL), false) AS passed
                FROM (
                    SELECT DISTINCT a.id AS aid, u.id AS sid
                    FROM assessments a
                    JOIN courses c ON c.title = a.course AND c.status = ?
                    JOIN batch_course bc ON bc.course_id = c.id
                    JOIN users u ON u.batch_id = bc.batch_id AND u.role = ? AND u.status = ?
                    WHERE a.type = ? AND a.status = ?
                ) p
                LEFT JOIN quiz_attempts qa ON qa.assessment_id = p.aid AND qa.student_id = p.sid
                GROUP BY p.aid, p.sid
            ) s
            SQL, [
            CourseStatus::Published->value, Role::Student->value, UserStatus::Active->value,
            AssessmentType::Quiz->value, AssessmentStatus::Published->value,
        ]);
        $passed = (int) ($row->passed ?? 0);
        $notPassed = (int) ($row->not_passed ?? 0);
        $notAttempted = (int) ($row->not_attempted ?? 0);

        return [
            'topics' => $topics,
            'students' => [
                'passed' => $passed,
                'not_passed' => $notPassed,
                'not_attempted' => $notAttempted,
                'total' => $passed + $notPassed + $notAttempted,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function certificates(): array
    {
        $by = DB::table('certificates')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $issued = (int) ($by[CertificateStatus::Issued->value] ?? 0);
        $total = (int) $by->sum();
        $categories = DB::table('certificates')
            ->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->join('course_categories', 'course_categories.id', '=', 'courses.course_category_id')
            ->selectRaw('course_categories.name AS label, COUNT(*) AS n')
            ->groupBy('course_categories.id', 'course_categories.name')->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->n])->all();


        return [
            'total' => $total,
            'by_category' => $categories,
            'status' => [
                ['label' => 'Issued', 'value' => $issued],
                ['label' => 'Pending', 'value' => (int) ($by[CertificateStatus::Pending->value] ?? 0)],
                ['label' => 'Rejected', 'value' => (int) ($by[CertificateStatus::Rejected->value] ?? 0)],
                ['label' => 'Cancelled', 'value' => (int) ($by[CertificateStatus::Cancelled->value] ?? 0)],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function community(): array
    {
        $recent = DB::table('community_messages')
            ->join('community_groups', 'community_groups.id', '=', 'community_messages.group_id')
            ->join('users', 'users.id', '=', 'community_messages.user_id')
            ->whereNull('community_messages.removed_at')
            ->orderByDesc('community_messages.created_at')->limit(5)
            ->get(['community_groups.name AS group', 'community_groups.source AS source', 'users.name AS who', 'community_messages.created_at AS at'])
            ->map(fn ($r) => [
                'text' => $r->source === 'dm' ? "Direct message from {$r->who}" : "{$r->who} posted in “{$r->group}”",
                'at' => Carbon::parse($r->at)->toIso8601String(),
                'tone' => $r->source === 'dm' ? 'amber' : 'green',
            ])->all();

        $since = $this->from;
        $messages = DB::table('community_messages')->where('created_at', '>=', $since);

        return [
            'recent' => $recent,
            'engagement' => [
                ['label' => 'Messages', 'value' => (clone $messages)->whereNull('removed_at')->count()],
                ['label' => 'Floor posts', 'value' => DB::table('common_floor_messages')->where('created_at', '>=', $since)->count()],
                ['label' => 'Groups', 'value' => DB::table('community_groups')->whereNull('deleted_at')->where('source', '<>', 'dm')->where('created_at', '>=', $since)->count()],
                ['label' => 'Direct chats', 'value' => DB::table('community_groups')->whereNull('deleted_at')->where('source', 'dm')->where('created_at', '>=', $since)->count()],
                ['label' => 'Removed', 'value' => (clone $messages)->whereNotNull('removed_at')->count()],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function logs(): array
    {
        $recent = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id')->limit(5)
            ->get(['audit_logs.action', 'users.name AS who', 'audit_logs.created_at AS at'])
            ->map(fn ($r) => [
                'text' => $r->action,
                'by' => $r->who,
                'at' => Carbon::parse($r->at)->toIso8601String(),
                'kind' => ActivityReportService::kindOf((string) $r->action),
                'tone' => preg_match('/fail|denied|reject|suspend|delete|remove|cancel/i', (string) $r->action) ? 'red' : 'green',
            ])->all();

        // Heatmap: weekday (Mon..Sun) x two-hour slot, counting actions in the selected range.
        $rows = DB::table('audit_logs')->where('created_at', '>=', $this->from)
            ->selectRaw('EXTRACT(ISODOW FROM created_at)::int AS dow, (EXTRACT(HOUR FROM created_at)::int / 2) AS slot, COUNT(*) AS n')
            ->groupBy('dow', 'slot')->get();
        $grid = array_fill(0, 7, array_fill(0, 12, 0));
        foreach ($rows as $r) {
            $grid[(int) $r->dow - 1][(int) $r->slot] = (int) $r->n;
        }

        return ['recent' => $recent, 'heatmap' => $grid];
    }
}
