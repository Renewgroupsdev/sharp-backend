<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\AttendanceRequestStatus;
use App\Enums\AttendanceStatus;
use App\Enums\CertificateStatus;
use App\Enums\CourseStatus;
use App\Enums\LessonPrivacy;
use App\Enums\Role;
use App\Enums\SubmissionStatus;
use App\Enums\UserStatus;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\CertificateSetting;
use App\Models\CommonFloorMessage;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard of each role, as a list of figures and blocks the web app draws without knowing the role:
 *   ['title', 'note', 'kpis' => [[label, value, hint]], 'blocks' => [pie | bars | progress | list]].
 * Every number is scoped to what the role may see: a mentor's own batch, a college's own students, the students taught by the
 * mentors of a member's organisation, a student's own record, or the whole programme for the office.
 */
class RoleDashboardService
{
    private const AT_RISK_ATTENDANCE = 75;

    private const BEHIND_PROGRESS = 40;

    private const WINDOW_DAYS = 30;

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        return match ($user->role) {
            Role::SuperAdmin, Role::Admin => $this->staff($user),
            Role::Ob => $this->observer(),
            Role::Member => $this->member($user),
            Role::Institution => $this->institution($user),
            Role::Mentor => $this->mentor($user),
            Role::Student => $this->student($user),
            default => ['title' => 'Dashboard', 'note' => null, 'kpis' => [], 'blocks' => []],
        };
    }

    /* ---------------------------------------------------------------- staff */

    private function staff(User $user): array
    {
        $students = User::students()->where('status', '<>', UserStatus::Suspended->value);
        $studentIds = (clone $students)->pluck('id');
        $today = $this->attendanceToday($studentIds);
        $pendingCerts = Certificate::where('status', CertificateStatus::Pending->value)->count();
        $reports = DB::table('attendance_requests')->where('status', AttendanceRequestStatus::Pending->value)->count();
        $toGrade = $this->toGrade();

        $certs = Certificate::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $blocks = [
            $this->pie('Attendance today', [['Present', $today['present']], ['Absent', $today['absent']], ['Not marked', $today['not_marked']]]),
            $this->progress('Learning progress by course', $this->courseProgress($studentIds, 6)),
            $this->list('Needs attention', [
                $this->item('Certificates waiting for review', $pendingCerts, 'red', '/app/certificates'),
                $this->item('Attendance reports to mark', $reports, 'red', '/app/attendance'),
                $this->item('Assignments to grade', $toGrade, 'amber', '/app/submissions'),
            ]),
            $this->pie('Certificate pipeline', array_map(fn (string $s) => [$s, (int) ($certs[$s] ?? 0)], CertificateStatus::values())),
            $this->bars('Batch fill (enrolled of seats)', $this->batchFill(), '%'),
            $this->list('Batches ending soon', $this->batchesEnding()),
            $this->list('Starting and ending soon', $this->batchDates()),
            $this->list('Notifications sent today', $this->notificationsToday()),
        ];

        if ($user->role === Role::SuperAdmin) {
            $byStatus = User::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
            $blocks[] = $this->pie('Accounts by status', [['Active', (int) ($byStatus[UserStatus::Active->value] ?? 0)], ['Invited', (int) ($byStatus[UserStatus::Invited->value] ?? 0)], ['Suspended', (int) ($byStatus[UserStatus::Suspended->value] ?? 0)]]);
            $blocks[] = $this->bars('Actions per day (audit log)', $this->actionsPerDay());
            $blocks[] = $this->list('Latest actions', AuditLog::with('actor:id,name')->latest('created_at')->latest('id')->limit(5)->get()
                ->map(fn (AuditLog $l) => $this->item(($l->actor?->name ?? 'System').': '.$l->action, $l->created_at->diffForHumans(short: true)))->all());
            $blocks[] = $this->list('Community health', [
                $this->item('Common Floor messages in 24 h', CommonFloorMessage::visible()->where('created_at', '>', now()->subDay())->count()),
                $this->item('Flagged messages to moderate', CommonFloorMessage::visible()->where('flagged', true)->count(), 'amber', '/app/community'),
            ]);
        }

        return [
            'title' => $user->role === Role::SuperAdmin ? 'Programme overview' : 'Operations today',
            'note' => null,
            'kpis' => [
                ['Students', $studentIds->count(), ''],
                ['Mentors', User::where('role', Role::Mentor->value)->where('status', '<>', UserStatus::Suspended->value)->count(), ''],
                ['Courses live', DB::table('courses')->where('status', CourseStatus::Published->value)->whereNull('deleted_at')->count(), ''],
                ['Active batches', Batch::where('status', '<>', 'closed')->count(), ''],
                ['Certificates issued', (int) ($certs[CertificateStatus::Issued->value] ?? 0), "{$pendingCerts} waiting"],
                ['Needs attention', $pendingCerts + $reports + $toGrade, ''],
            ],
            'blocks' => $blocks,
        ];
    }

    /* ------------------------------------------------------------- observer */

    private function observer(): array
    {
        $studentIds = User::students()->where('status', '<>', UserStatus::Suspended->value)->pluck('id');
        $quiz = $this->quizOutcome($studentIds, now()->subDays(self::WINDOW_DAYS));
        $passRate = $this->pct($quiz['passed'], $quiz['passed'] + $quiz['not_passed']);

        $batchRows = $this->batchRows();
        $byBatch = $batchRows->map(fn ($b) => [$b['name'], $this->attendanceRate(User::students()->where('batch_id', $b['id'])->pluck('id')) ?? 0]);
        $watch = [];
        foreach ($batchRows as $b) {
            $ids = User::students()->where('batch_id', $b['id'])->pluck('id');
            $att = $this->attendanceRate($ids);
            if ($att !== null && $att < 80) {
                $watch[] = $this->item("{$b['name']} attendance {$att}%", 'below 80%', 'amber');
            }
            $q = $this->quizOutcome($ids, now()->subDays(self::WINDOW_DAYS));
            $rate = $this->pct($q['passed'], $q['passed'] + $q['not_passed']);
            if (($q['passed'] + $q['not_passed']) > 0 && $rate < 60) {
                $watch[] = $this->item("{$b['name']} quiz pass rate {$rate}%", 'low', 'red');
            }
        }

        return [
            'title' => 'Programme results',
            'note' => 'Read-only view of how the programme is doing.',
            'kpis' => [
                ['Active batches', $batchRows->count(), ''],
                ['Avg attendance', ($this->attendanceRate($studentIds) ?? 0).'%', 'of the course days'],
                ['Avg progress', $this->avgProgress($studentIds).'%', ''],
                ['Quiz pass rate', $passRate.'%', 'last '.self::WINDOW_DAYS.' days'],
                ['Courses completed', DB::table('learning_progress')->where('progress', '>=', 100)->count(), ''],
                ['Community posts', CommonFloorMessage::visible()->where('created_at', '>', now()->subDay())->count(), 'last 24 h'],
            ],
            'blocks' => [
                $this->bars('Course attendance by batch', $byBatch->all(), '%'),
                $this->progress('Course completion', $this->courseProgress($studentIds, 6)),
                $this->pie('Quiz results', [['Passed', $quiz['passed']], ['Not passed', $quiz['not_passed']], ['Awaiting marking', $quiz['waiting']]]),
                $this->list('Batches that need a look', $watch ?: [$this->item('Nothing stands out', '')]),
                $this->bars('Enrolments by course', $this->enrolmentsByCourse($studentIds)),
            ],
        ];
    }

    /* --------------------------------------------------------------- member */

    private function member(User $user): array
    {
        $mentors = User::where('role', Role::Mentor->value)->where('org', $user->org)->where('status', '<>', UserStatus::Suspended->value);
        $batchIds = (clone $mentors)->whereNotNull('batch_id')->pluck('batch_id')->unique();
        $students = User::students()->where('status', '<>', UserStatus::Suspended->value)->whereIn('batch_id', $batchIds);

        return $this->scopedPeople($students->pluck('id'), [
            'title' => $user->org ?: 'Our students',
            'note' => 'The students of the batches taught by your organisation\'s mentors.',
            'mentors' => (clone $mentors)->count(),
            'extra' => [],
        ], $batchIds->count());
    }

    /* ---------------------------------------------------------- institution */

    private function institution(User $user): array
    {
        $students = User::students()->where('status', '<>', UserStatus::Suspended->value)->where('college_id', $user->college_id ?? 0);
        $batchIds = (clone $students)->whereNotNull('batch_id')->pluck('batch_id')->unique();
        $mentors = User::where('role', Role::Mentor->value)->whereIn('batch_id', $batchIds)->orderBy('name')->get(['name', 'org']);

        $out = $this->scopedPeople($students->pluck('id'), [
            'title' => $user->org ?: 'Our college',
            'note' => 'Your college\'s students in the programme.',
            'mentors' => $mentors->count(),
            'extra' => [],
        ], $batchIds->count());

        $byBatch = $batchIds->map(function ($id) use ($user) {
            $ids = User::students()->where('college_id', $user->college_id ?? 0)->where('batch_id', $id)->pluck('id');

            return [Batch::find($id)?->name ?? "Batch {$id}", $this->attendanceRate($ids) ?? 0];
        })->all();

        $out['blocks'][] = $this->bars('Attendance by batch', $byBatch, '%');
        $out['blocks'][] = $this->list('Reports', [$this->item('Attendance, progress and certificate reports', 'Open', '', '/app/reports')]);
        $out['blocks'][] = $this->list('Mentor contacts', $mentors->map(fn ($m) => $this->item($m->name.($m->org ? ' · '.$m->org : ''), ''))->all() ?: [$this->item('No mentors assigned yet', '')]);

        return $out;
    }

    /** The shared body of the member and institution dashboards: figures for a set of students. */
    private function scopedPeople(Collection $studentIds, array $head, int $batches): array
    {
        $metrics = $this->studentMetrics($studentIds);
        $atRisk = $metrics->filter(fn ($m) => $m['att'] !== null && $m['att'] < self::AT_RISK_ATTENDANCE);
        $behind = $metrics->filter(fn ($m) => ! ($m['att'] !== null && $m['att'] < self::AT_RISK_ATTENDANCE) && $m['progress'] < self::BEHIND_PROGRESS);
        $certs = Certificate::whereIn('user_id', $studentIds)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $issued = (int) ($certs[CertificateStatus::Issued->value] ?? 0);
        $pending = (int) ($certs[CertificateStatus::Pending->value] ?? 0);

        return [
            'title' => $head['title'],
            'note' => $studentIds->isEmpty() ? 'No students to show yet.' : $head['note'],
            'kpis' => [
                ['Students', $studentIds->count(), "{$batches} ".($batches === 1 ? 'batch' : 'batches')],
                ['Attendance', ($this->attendanceRate($studentIds) ?? 0).'%', 'of the course days'],
                ['Avg progress', $this->avgProgress($studentIds).'%', ''],
                ['Certificates', $issued, "{$pending} waiting"],
                ['Mentors', $head['mentors'], ''],
                ['At risk', $atRisk->count(), 'attendance under '.self::AT_RISK_ATTENDANCE.'%'],
            ],
            'blocks' => [
                $this->progress('Progress by course', $this->courseProgress($studentIds, 6)),
                $this->pie('Students by status', [['On track', $metrics->count() - $atRisk->count() - $behind->count()], ['Behind', $behind->count()], ['At risk', $atRisk->count()]]),
                $this->list('Students to follow up', $atRisk->sortBy('att')->take(4)->map(fn ($m) => $this->item("{$m['name']}: attendance {$m['att']}%", 'low', 'red'))
                    ->concat($behind->sortBy('progress')->take(3)->map(fn ($m) => $this->item("{$m['name']}: progress {$m['progress']}%", 'behind', 'amber')))->values()->all() ?: [$this->item('Everyone is on track', '')]),
                $this->bars('Attendance by week', $this->attendanceByWeek($studentIds), '%'),
                $this->list('Top performers', $metrics->sortByDesc('progress')->take(3)->map(fn ($m) => $this->item($m['name'], $m['progress'].'%'))->values()->all() ?: [$this->item('No results yet', '')]),
            ],
        ];
    }

    /* --------------------------------------------------------------- mentor */

    private function mentor(User $user): array
    {
        $studentIds = User::students()->where('status', '<>', UserStatus::Suspended->value)->where('batch_id', $user->batch_id ?? 0)->pluck('id');
        $today = $this->attendanceToday($studentIds);
        $reports = DB::table('attendance_requests')->whereIn('student_id', $studentIds)->where('status', AttendanceRequestStatus::Pending->value)->count();
        $toGrade = $this->toGrade($studentIds);
        $certs = Certificate::whereIn('user_id', $studentIds)->where('status', CertificateStatus::Pending->value)->whereNull('mentor_decision')->count();
        $byHand = DB::table('quiz_attempts')->whereIn('student_id', $studentIds)->whereNotNull('submitted_at')->where('needs_manual_grading', true)->count();
        $metrics = $this->studentMetrics($studentIds);
        $behind = $metrics->filter(fn ($m) => ($m['att'] !== null && $m['att'] < self::AT_RISK_ATTENDANCE) || $m['progress'] < self::BEHIND_PROGRESS);
        $written = $this->writtenStates($studentIds);

        return [
            'title' => 'My batch',
            'note' => $studentIds->isEmpty() ? 'No students are assigned to your batch yet.' : null,
            'kpis' => [
                ['My students', $studentIds->count(), $user->batch?->name ?? ''],
                ['Present today', $today['present'], 'of '.$studentIds->count()],
                ['Reports to mark', $reports, ''],
                ['Avg progress', $this->avgProgress($studentIds).'%', ''],
                ['To grade', $toGrade, 'assignments'],
                ['Certificates to review', $certs, ''],
            ],
            'blocks' => [
                $this->pie('Attendance today', [['Present', $today['present']], ['Absent', $today['absent']], ['Not marked', $today['not_marked']]]),
                $this->progress('Progress by module', $this->moduleProgress($user->batch_id, $studentIds)),
                $this->list('To do today', [
                    $this->item('Attendance reports to mark', $reports, 'red', '/app/manual-attendance'),
                    $this->item('Assignments to grade', $toGrade, 'amber', '/app/submissions'),
                    $this->item('Certificates to review', $certs, 'amber', '/app/certificate-review'),
                    $this->item('Quiz answers to mark by hand', $byHand, 'amber', '/app/submissions'),
                ]),
                $this->list('Students behind', $behind->sortBy('progress')->take(5)->map(fn ($m) => $this->item($m['name'].': '.($m['att'] !== null && $m['att'] < self::AT_RISK_ATTENDANCE ? "attendance {$m['att']}%" : "progress {$m['progress']}%"), '', $m['att'] !== null && $m['att'] < self::AT_RISK_ATTENDANCE ? 'red' : 'amber'))->values()->all() ?: [$this->item('Everyone is keeping up', '')]),
                $this->pie('Assignments (my batch)', [['New', $written['submitted']], ['In review', $written['under_review']], ['Graded', $written['graded']], ['Returned', $written['returned']]]),
                $this->list('Upcoming deadlines', $this->deadlines($user->batch_id)),
            ],
        ];
    }

    /* -------------------------------------------------------------- student */

    private function student(User $user): array
    {
        $courses = $this->studentCourses($user);
        $courseIds = $courses->pluck('id');
        $lessons = $this->studentLessons($user, $courseIds);
        $progress = $courses->isEmpty() ? 0 : (int) round($courses->avg('progress'));
        $attendance = $this->attendanceRate(collect([$user->id]));
        $due = $this->studentDue($user, $courses);
        $settings = CertificateSetting::current();
        $quizAvg = (int) round((float) DB::table('quiz_results')->where('student_id', $user->id)->avg('result_percent'));
        $issued = Certificate::where('user_id', $user->id)->where('status', CertificateStatus::Issued->value)->count();
        $todayRow = DB::table('attendance')->where('student_id', $user->id)->whereDate('att_date', today())->value('status');
        $todayReq = DB::table('attendance_requests')->where('student_id', $user->id)->whereDate('att_date', today())->first();
        $next = $lessons['next'];
        $month = $this->attendanceMonth($user);

        return [
            'title' => 'My learning',
            'note' => $courses->isEmpty() ? 'You are not enrolled in a course yet.' : null,
            'kpis' => [
                ['Courses', $courses->count(), 'enrolled'],
                ['Overall progress', $progress.'%', ''],
                ['Due this week', $due->filter(fn ($d) => $d['days'] <= 7)->count(), 'to submit'],
                ['Certificates', $issued, 'issued'],
                ['Unread', Notification::where('user_id', $user->id)->whereNull('read_at')->count(), 'notifications'],
            ],
            'blocks' => [
                $this->list('Continue learning', [$next
                    ? $this->item("{$next['course']}: {$next['lesson']}", 'Open', '', "/app/learning/{$next['course_id']}")
                    : $this->item($courses->isEmpty() ? 'No courses yet' : 'You have finished every lesson', '')]),
                $this->progress('My courses', $courses->map(fn ($c) => [$c->title, (int) $c->progress])->all()),
                $this->pie('Monthly attendance · '.today()->format('F Y'), [['Present', $month['present']], ['Absent', $month['absent']], ['Pending', $month['pending']]], true),
                $this->list('Attendance today', [
                    $this->item($todayRow ? "Marked {$todayRow}" : ($todayReq ? "You reported {$todayReq->requested_status}, waiting to be marked" : 'Not reported yet'), $todayRow ? '' : ($todayReq ? 'Pending' : 'Report now'), $todayRow || $todayReq ? ($todayRow ? '' : 'amber') : 'red', '/app/attendance'),
                    $this->item('Course attendance', ($attendance ?? 0).'%'),
                ]),
                $this->list('Due soon', $due->take(5)->map(fn ($d) => $this->item($d['title'], $d['label'], $d['days'] <= 3 ? 'red' : ($d['days'] <= 7 ? 'amber' : ''), '/app/assessments'))->values()->all() ?: [$this->item('Nothing due', '')]),
                $this->progress('Certificate eligibility', [
                    ['Lessons completed', $progress],
                    ["Attendance (minimum {$settings->min_attendance}%)", min(100, $attendance ?? 0)],
                    ...($settings->min_score > 0 ? [["Quiz average (minimum {$settings->min_score}%)", min(100, $quizAvg)]] : []),
                ]),
                $this->list('Latest results', $this->latestResults($user)),
            ],
        ];
    }

    /* ------------------------------------------------------------- building */

    /**
     * This month so far: days attended, days marked absent, and the working days with no mark yet (pending).
     *
     * @return array{present: int, absent: int, pending: int}
     */
    private function attendanceMonth(User $user): array
    {
        $from = today()->startOfMonth();
        $rows = DB::table('attendance')->where('student_id', $user->id)->whereDate('att_date', '>=', $from->toDateString())->whereDate('att_date', '<=', today()->toDateString())->get(['att_date', 'status']);
        $present = $rows->filter(fn ($r) => $r->status !== AttendanceStatus::Absent->value)->pluck('att_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->count();
        $absent = $rows->filter(fn ($r) => $r->status === AttendanceStatus::Absent->value)->pluck('att_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->count();
        $working = AttendanceSummary::workingDays($from, today());

        return ['present' => $present, 'absent' => $absent, 'pending' => max(0, $working - $present - $absent)];
    }

    private function pie(string $title, array $slices, bool $threeD = false): array
    {
        return ['type' => 'pie', 'title' => $title, 'three_d' => $threeD, 'slices' => array_map(fn ($s) => ['label' => $s[0], 'value' => (int) $s[1]], $slices)];
    }

    private function bars(string $title, array $items, string $unit = ''): array
    {
        return ['type' => 'bars', 'title' => $title, 'unit' => $unit, 'items' => array_values(array_map(fn ($i) => ['label' => (string) $i[0], 'value' => (int) $i[1]], $items))];
    }

    private function progress(string $title, array $items): array
    {
        return ['type' => 'progress', 'title' => $title, 'items' => array_values(array_map(fn ($i) => ['label' => (string) $i[0], 'value' => max(0, min(100, (int) $i[1]))], $items))];
    }

    private function list(string $title, array $items): array
    {
        return ['type' => 'list', 'title' => $title, 'items' => array_values($items)];
    }

    /** A line of a list block: the text, an optional badge (with a tone: red, amber or none) and an optional link in the app. */
    private function item(string $text, string|int $badge = '', string $tone = '', ?string $url = null): array
    {
        return ['text' => $text, 'badge' => (string) $badge, 'tone' => $tone, 'url' => $url];
    }

    private function pct(int $part, int $whole): int
    {
        return $whole ? (int) round($part / $whole * 100) : 0;
    }

    /* ------------------------------------------------------------- measures */

    /** Share of marked days that were present, over the last 30 days; null when nothing is marked. */
    private function attendanceRate(Collection $studentIds, int $days = self::WINDOW_DAYS): ?int
    {
        if ($studentIds->isEmpty()) {
            return null;
        }
        // The average course attendance: each student's days attended out of the working days of their course (batch) dates.
        $rates = collect(app(AttendanceSummary::class)->ratesFor($studentIds));

        return $rates->isEmpty() ? null : (int) round($rates->avg());
    }

    /** @return array{present: int, absent: int, not_marked: int} */
    private function attendanceToday(Collection $studentIds): array
    {
        $rows = DB::table('attendance')->whereIn('student_id', $studentIds)->whereDate('att_date', today())->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $present = (int) ($rows[AttendanceStatus::Present->value] ?? 0) + (int) ($rows[AttendanceStatus::Manual->value] ?? 0);
        $absent = (int) ($rows[AttendanceStatus::Absent->value] ?? 0);

        return ['present' => $present, 'absent' => $absent, 'not_marked' => max(0, $studentIds->count() - $present - $absent)];
    }

    private function avgProgress(Collection $studentIds): int
    {
        return $studentIds->isEmpty() ? 0 : (int) round((float) DB::table('learning_progress')->whereIn('user_id', $studentIds)->avg('progress'));
    }

    /** @return list<array{0: string, 1: int}> average progress per course among the given students */
    private function courseProgress(Collection $studentIds, int $limit): array
    {
        return DB::table('learning_progress as lp')->join('courses as c', 'c.id', '=', 'lp.course_id')->whereNull('c.deleted_at')
            ->whereIn('lp.user_id', $studentIds)->groupBy('c.id', 'c.title')->selectRaw('c.title, ROUND(AVG(lp.progress)) AS p')
            ->orderByDesc('p')->limit($limit)->get()->map(fn ($r) => [$r->title, (int) $r->p])->all();
    }

    /** @return Collection<int, array{id: int, name: string, att: ?int, progress: int}> */
    private function studentMetrics(Collection $studentIds): Collection
    {
        if ($studentIds->isEmpty()) {
            return collect();
        }
        $att = app(AttendanceSummary::class)->ratesFor($studentIds);
        $prog = DB::table('learning_progress')->whereIn('user_id', $studentIds)->groupBy('user_id')->selectRaw('user_id, AVG(progress) AS p')->pluck('p', 'user_id');

        return User::whereIn('id', $studentIds)->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name,
            'att' => $att[$u->id] ?? null,
            'progress' => (int) round((float) ($prog[$u->id] ?? 0)),
        ]);
    }

    /** @return list<array{0: string, 1: int}> */
    private function attendanceByWeek(Collection $studentIds): array
    {
        $out = [];
        for ($w = 3; $w >= 0; $w--) {
            $from = today()->subDays($w * 7 + 6);
            $to = today()->subDays($w * 7);
            $row = $studentIds->isEmpty() ? null : DB::table('attendance')->whereIn('student_id', $studentIds)->whereBetween('att_date', [$from, $to])
                ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS ok', [AttendanceStatus::Present->value, AttendanceStatus::Manual->value])->first();
            $out[] = ['W'.(4 - $w), $row && $row->total ? $this->pct((int) $row->ok, (int) $row->total) : 0];
        }

        return $out;
    }

    /** @return array{passed: int, not_passed: int, waiting: int} */
    private function quizOutcome(Collection $studentIds, $since): array
    {
        $q = DB::table('quiz_attempts')->whereIn('student_id', $studentIds)->whereNotNull('submitted_at')->where('submitted_at', '>=', $since);

        return [
            'passed' => (clone $q)->where('needs_manual_grading', false)->where('passed', true)->count(),
            'not_passed' => (clone $q)->where('needs_manual_grading', false)->where(fn ($w) => $w->where('passed', false)->orWhereNull('passed'))->count(),
            'waiting' => (clone $q)->where('needs_manual_grading', true)->count(),
        ];
    }

    private function toGrade(?Collection $studentIds = null): int
    {
        return DB::table('assessment_submissions')->when($studentIds, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])->count();
    }

    /** @return array{submitted: int, under_review: int, graded: int, returned: int} */
    private function writtenStates(Collection $studentIds): array
    {
        $rows = DB::table('assessment_submissions')->whereIn('student_id', $studentIds)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return ['submitted' => (int) ($rows['submitted'] ?? 0), 'under_review' => (int) ($rows['under_review'] ?? 0), 'graded' => (int) ($rows['graded'] ?? 0), 'returned' => (int) ($rows['returned'] ?? 0)];
    }

    /* --------------------------------------------------------------- staff bits */

    /** @return Collection<int, array{id: int, name: string}> */
    private function batchRows(): Collection
    {
        return Batch::where('status', '<>', 'closed')->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]);
    }

    /** @return list<array{0: string, 1: int}> */
    private function batchFill(): array
    {
        return Batch::where('status', '<>', 'closed')->where('seats', '>', 0)->orderBy('name')->limit(6)->get()
            ->map(fn (Batch $b) => [$b->name, $this->pct(User::students()->where('batch_id', $b->id)->count(), (int) $b->seats)])->all();
    }

    /** Batches that end within 14 days (or ended and are still open), nearest first; red inside a week. @return list<array<string, mixed>> */
    private function batchesEnding(): array
    {
        // Open batches up to 14 days from their end (or past it), and closed ones for 30 days after they ended.
        $rows = Batch::query()->whereDate('end_date', '<=', today()->addDays(14))
            ->where(fn ($q) => $q->where('status', '<>', 'closed')->orWhereDate('end_date', '>=', today()->subDays(30)))
            ->orderBy('end_date')->limit(8)->get()
            ->map(function (Batch $b) {
                $days = (int) today()->diffInDays($b->end_date, false);
                $when = $days < 0 ? ($days === -1 ? 'ended yesterday' : 'ended '.abs($days).' days ago') : ($days === 0 ? 'ends today' : ($days === 1 ? 'ends tomorrow' : "ends in {$days} days"));

                return $this->item("{$b->name} · {$b->end_date->format('d M Y')}", $when, $days <= 7 ? 'red' : 'amber', '/app/institutes/batches');
            })->all();

        return $rows ?: [$this->item('No batch ends in the next 14 days', '')];
    }

    /** @return list<array<string, mixed>> */
    private function batchDates(): array
    {
        $soon = today()->addDays(30);
        $rows = [];
        foreach (Batch::whereBetween('start_date', [today(), $soon])->orderBy('start_date')->limit(3)->get() as $b) {
            $rows[] = $this->item("{$b->name} starts", $b->start_date->format('d M'));
        }
        foreach (Batch::whereBetween('end_date', [today(), $soon])->orderBy('end_date')->limit(3)->get() as $b) {
            $rows[] = $this->item("{$b->name} ends", $b->end_date->format('d M'));
        }

        return $rows ?: [$this->item('No batch starts or ends in the next 30 days', '')];
    }

    /** @return list<array<string, mixed>> */
    private function notificationsToday(): array
    {
        $today = Notification::whereDate('created_at', today());

        return [
            $this->item('Notifications delivered', (clone $today)->count()),
            $this->item('Messages written by staff', (clone $today)->where('type', 'message')->count()),
        ];
    }

    /** @return list<array{0: string, 1: int}> */
    private function actionsPerDay(): array
    {
        $rows = AuditLog::where('created_at', '>=', today()->subDays(4))->selectRaw('created_at::date AS d, COUNT(*) AS n')->groupBy('d')->pluck('n', 'd');
        $out = [];
        for ($i = 4; $i >= 0; $i--) {
            $day = today()->subDays($i);
            $out[] = [$day->format('D'), (int) ($rows[$day->toDateString()] ?? 0)];
        }

        return $out;
    }

    /** @return list<array{0: string, 1: int}> students per course (through their batch) */
    private function enrolmentsByCourse(Collection $studentIds): array
    {
        return DB::table('users as u')->join('batch_course as bc', 'bc.batch_id', '=', 'u.batch_id')->join('courses as c', 'c.id', '=', 'bc.course_id')
            ->whereNull('c.deleted_at')->whereIn('u.id', $studentIds)->groupBy('c.id', 'c.title')->selectRaw('c.title, COUNT(*) AS n')
            ->orderByDesc('n')->limit(6)->get()->map(fn ($r) => [$r->title, (int) $r->n])->all();
    }

    /* -------------------------------------------------------------- mentor bits */

    /** @return list<array{0: string, 1: int}> share of the batch's students that finished each module's lessons */
    private function moduleProgress(?int $batchId, Collection $studentIds): array
    {
        if (! $batchId || $studentIds->isEmpty()) {
            return [];
        }
        $chapters = DB::table('course_chapters as ch')->join('batch_course as bc', 'bc.course_id', '=', 'ch.course_id')->where('bc.batch_id', $batchId)
            ->where('ch.status', 'Published')->orderBy('ch.course_id')->orderBy('ch.position')->limit(6)->get(['ch.id', 'ch.name']);
        $out = [];
        foreach ($chapters as $ch) {
            $lessonIds = DB::table('course_lessons')->where('course_chapter_id', $ch->id)->where('privacy', '<>', LessonPrivacy::Private->value)->pluck('id');
            $done = $lessonIds->isEmpty() ? 0 : DB::table('lesson_progress')->whereIn('user_id', $studentIds)->whereIn('course_lesson_id', $lessonIds)->whereNotNull('completed_at')->count();
            $out[] = [$ch->name, $this->pct($done, $lessonIds->count() * $studentIds->count())];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function deadlines(?int $batchId): array
    {
        if (! $batchId) {
            return [$this->item('No deadlines', '')];
        }
        $titles = DB::table('batch_course as bc')->join('courses as c', 'c.id', '=', 'bc.course_id')->where('bc.batch_id', $batchId)->pluck('c.title');
        $rows = Assessment::whereIn('course', $titles)->where('status', AssessmentStatus::Published->value)->where('type', AssessmentType::Assignment->value)
            ->where('due_date', '>=', today())->orderBy('due_date')->limit(5)->get();

        return $rows->map(fn (Assessment $a) => $this->item($a->title, $a->due_date->format('d M'), today()->diffInDays($a->due_date) <= 3 ? 'amber' : '', '/app/assessments'))->all() ?: [$this->item('No deadlines coming up', '')];
    }

    /* ------------------------------------------------------------- student bits */

    /** @return Collection<int, object> the courses the student's batch takes, with the student's progress */
    private function studentCourses(User $user): Collection
    {
        return DB::table('batch_course as bc')->join('courses as c', 'c.id', '=', 'bc.course_id')->whereNull('c.deleted_at')
            ->where('c.status', CourseStatus::Published->value)->where('bc.batch_id', $user->batch_id ?? 0)
            ->leftJoin('learning_progress as lp', fn ($j) => $j->on('lp.course_id', '=', 'c.id')->where('lp.user_id', $user->id))
            ->orderBy('c.title')->get(['c.id', 'c.title', DB::raw('COALESCE(lp.progress, 0) AS progress')]);
    }

    /** @return array{next: ?array{course: string, course_id: int, lesson: string}} */
    private function studentLessons(User $user, Collection $courseIds): array
    {
        $done = DB::table('lesson_progress')->where('user_id', $user->id)->whereNotNull('completed_at')->pluck('course_lesson_id')->flip();
        $rows = DB::table('course_lessons as l')->join('course_chapters as ch', 'ch.id', '=', 'l.course_chapter_id')->join('courses as c', 'c.id', '=', 'ch.course_id')
            ->whereIn('c.id', $courseIds)->where('ch.status', 'Published')->where('l.privacy', '<>', LessonPrivacy::Private->value)
            ->orderBy('c.title')->orderBy('ch.position')->orderBy('l.position')->orderBy('l.id')->get(['l.id', 'l.name', 'c.id as course_id', 'c.title as course']);
        $next = $rows->first(fn ($r) => ! $done->has($r->id));

        return ['next' => $next ? ['course' => $next->course, 'course_id' => (int) $next->course_id, 'lesson' => $next->name] : null];
    }

    /** @return Collection<int, array{title: string, label: string, days: int}> assignments and assessments still to hand in, nearest first */
    private function studentDue(User $user, Collection $courses): Collection
    {
        $submitted = DB::table('assessment_submissions')->where('student_id', $user->id)->pluck('assessment_id');

        return Assessment::whereIn('course', $courses->pluck('title'))->where('status', AssessmentStatus::Published->value)
            ->where('type', AssessmentType::Assignment->value)->whereNotIn('id', $submitted)
            ->where('due_date', '>=', today())->orderBy('due_date')->limit(8)->get()
            ->map(fn (Assessment $a) => ['title' => $a->title, 'label' => $a->due_date->format('d M'), 'days' => (int) today()->diffInDays($a->due_date)]);
    }

    /** @return list<array<string, mixed>> */
    private function latestResults(User $user): array
    {
        $graded = DB::table('assessment_submissions as s')->join('assessments as a', 'a.id', '=', 's.assessment_id')->where('s.student_id', $user->id)
            ->where('s.status', SubmissionStatus::Graded->value)->orderByDesc('s.graded_at')->limit(2)->get(['a.title', 'a.max_marks', 's.marks_obtained', 's.feedback']);
        $quizzes = DB::table('quiz_results as r')->join('assessments as a', 'a.id', '=', 'r.assessment_id')->where('r.student_id', $user->id)
            ->orderByDesc('r.updated_at')->limit(2)->get(['a.title', 'a.min_percent', 'r.marks', 'r.total', 'r.result_percent']);

        $items = [];
        foreach ($quizzes as $q) {
            $items[] = $this->item("{$q->title}: {$q->marks}/{$q->total} ({$q->result_percent}%)", $q->result_percent >= $q->min_percent ? 'Passed' : 'Not passed', $q->result_percent >= $q->min_percent ? '' : 'amber');
        }
        foreach ($graded as $g) {
            $items[] = $this->item("{$g->title}: {$g->marks_obtained}/{$g->max_marks}".($g->feedback ? ' — '.\Illuminate\Support\Str::limit($g->feedback, 60) : ''), '');
        }

        return $items ?: [$this->item('No results yet', '')];
    }
}
