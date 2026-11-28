<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Attendance percentages for one student.
 *  - By month: days attended out of the working days of that month (up to today).
 *  - By course: days attended out of the working days between the course's start and end dates. A course runs for a batch,
 *    so its dates are the batch's dates. "So far" uses only the working days that have already passed.
 * A working day is Monday to Friday. A day counts as attended when its status is not Absent (Present or Manual).
 */
class AttendanceSummary
{
    /** Working days (Mon-Fri) from $from to $to, both included. */
    public static function workingDays(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($to->lt($from)) {
            return 0;
        }
        $days = 0;
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            if ($d->isWeekday()) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Course attendance of many students at once: days attended out of the working days between their batch's start and end
     * dates. A student without a batch that has both dates gets no figure.
     *
     * @param  iterable<int>  $studentIds
     * @param  ?CarbonInterface  $asOf  count only the days attended up to this date
     * @return array<int, int> student id => percentage
     */
    public function ratesFor(iterable $studentIds, ?CarbonInterface $asOf = null): array
    {
        $ids = collect($studentIds)->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $students = DB::table('users')->whereIn('id', $ids)->get(['id', 'batch_id']);
        $batches = DB::table('batches')->whereIn('id', $students->pluck('batch_id')->filter()->unique())->get(['id', 'start_date', 'end_date'])->keyBy('id');
        $attended = DB::table('attendance')->whereIn('student_id', $ids)->where('status', '<>', AttendanceStatus::Absent->value)
            ->when($asOf, fn ($q) => $q->whereDate('att_date', '<=', $asOf->toDateString()))->get(['student_id', 'att_date'])
            ->groupBy('student_id')->map(fn ($rows) => $rows->map(fn ($r) => Carbon::parse($r->att_date)->toDateString())->unique()->values());

        $out = [];
        foreach ($students as $st) {
            $b = $batches[$st->batch_id] ?? null;
            if (! $b || ! $b->start_date || ! $b->end_date) {
                continue;
            }
            $from = Carbon::parse($b->start_date)->toDateString();
            $to = Carbon::parse($b->end_date)->toDateString();
            $duration = self::workingDays(Carbon::parse($from), Carbon::parse($to));
            $present = ($attended[$st->id] ?? collect())->filter(fn ($d) => $d >= $from && $d <= $to)->count();
            if ($duration > 0) {
                $out[$st->id] = min(100, (int) round($present / $duration * 100));
            }
        }

        return $out;
    }

    /** @return array{months: list<array<string, mixed>>, courses: list<array<string, mixed>>} */
    public function for(User $student): array
    {
        $today = Carbon::today();
        $attended = $student->attendance()->where('status', '<>', AttendanceStatus::Absent->value)->pluck('att_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values();
        $absent = $student->attendance()->where('status', AttendanceStatus::Absent->value)->pluck('att_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values();
        $all = $attended->merge($absent)->unique();

        return ['months' => $this->months($attended, $all, $today), 'courses' => $this->courses($student, $attended, $absent, $today)];
    }

    /** @return list<array<string, mixed>> newest month first */
    private function months($attended, $all, Carbon $today): array
    {
        if ($all->isEmpty()) {
            return [];
        }
        $first = Carbon::parse($all->min())->startOfMonth();
        $rows = [];
        for ($m = $first->copy(); $m->lte($today); $m->addMonth()) {
            $from = $m->copy()->startOfMonth();
            $to = $m->copy()->endOfMonth()->min($today);
            $working = self::workingDays($from, $to);
            $present = $attended->filter(fn ($d) => $d >= $from->toDateString() && $d <= $to->toDateString())->count();
            $rows[] = [
                'month' => $m->format('Y-m'), 'label' => $m->format('F Y'),
                'working_days' => $working, 'present' => $present,
                'pct' => $working ? min(100, (int) round($present / $working * 100)) : 0,
            ];
        }

        return array_reverse($rows);
    }

    /** @return list<array<string, mixed>> */
    private function courses(User $student, $attended, $absent, Carbon $today): array
    {
        if (! $student->batch_id) {
            return [];
        }
        $batch = DB::table('batches')->where('id', $student->batch_id)->first(['start_date', 'end_date']);
        if (! $batch || ! $batch->start_date || ! $batch->end_date) {
            return [];
        }
        $start = Carbon::parse($batch->start_date)->startOfDay();
        $end = Carbon::parse($batch->end_date)->startOfDay();
        $courses = \App\Models\Course::query()->forStudent($student)->orderBy('title')->get(['courses.id', 'courses.title']);

        $inRange = fn ($list) => $list->filter(fn ($d) => $d >= $start->toDateString() && $d <= $end->toDateString())->count();
        $duration = self::workingDays($start, $end);
        $elapsed = $today->lt($start) ? 0 : self::workingDays($start, $today->min($end));
        $present = $inRange($attended);

        return $courses->map(fn ($c) => [
            'course_id' => $c->id, 'course' => $c->title,
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'working_days' => $duration, 'elapsed_days' => $elapsed,
            'present' => $present, 'absent' => $inRange($absent),
            'pct' => $duration ? min(100, (int) round($present / $duration * 100)) : 0,
            'pct_so_far' => $elapsed ? min(100, (int) round($present / $elapsed * 100)) : 0,
        ])->values()->all();
    }
}
