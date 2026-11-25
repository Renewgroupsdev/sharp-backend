<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceService
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    /** @return array{records: Collection, rate: int} */
    public function forStudent(User $student, int $limit = 200): array
    {
        $records = $student->attendance()
            ->with('marker:id,name')
            ->orderByDesc('att_date')
            ->limit($limit)
            ->get();

        $stats = $student->attendance()
            ->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE status <> ?) AS attended', [AttendanceStatus::Absent->value])
            ->first();

        $rate = $stats->total ? (int) round($stats->attended / $stats->total * 100) : 0;

        return ['records' => $records, 'rate' => $rate];
    }

    /** Days the staff list covers when no start date is given. */
    public const DEFAULT_WINDOW_DAYS = 90;

    /**
     * Attendance of many students, newest first. A mentor only sees their own batch.
     *
     * @param  array{batch_id?: int, college_id?: int, student_id?: string, date_from?: string, date_to?: string, status?: string}  $filters
     * @return Collection<int, Attendance>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        return Attendance::query()
            ->with(['student:id,public_id,name,org,batch_id', 'student.batch:id,name', 'marker:id,name'])
            ->whereHas('student', fn (Builder $s) => $s
                ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->where('batch_id', $actor->batch_id ?? 0))
                ->when(! empty($filters['batch_id']), fn (Builder $q) => $q->where('batch_id', $filters['batch_id']))
                ->when(! empty($filters['college_id']), fn (Builder $q) => $q->where('college_id', $filters['college_id']))
                ->when(! empty($filters['student_id']), fn (Builder $q) => $q->where('public_id', $filters['student_id'])))
            ->whereDate('att_date', '>=', $filters['date_from'] ?? Carbon::today()->subDays(self::DEFAULT_WINDOW_DAYS)->toDateString())
            ->when(! empty($filters['date_to']), fn (Builder $q) => $q->whereDate('att_date', '<=', $filters['date_to']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderByDesc('att_date')->orderByDesc('id')
            ->limit(5000)
            ->get();
    }

    /**
     * Insert or overwrite the single row for (student, date).
     *
     * @param  array{lat?: ?float, lng?: ?float, distance_m?: ?int, on_site?: ?bool}  $location  where the student was, if known
     */
    public function mark(User $marker, User $student, AttendanceStatus $status, ?string $reason, ?string $date, array $location = [], bool $audit = true, bool $notify = true): string
    {
        Attendance::updateOrCreate(
            ['student_id' => $student->id, 'att_date' => $date ?? Carbon::today()->toDateString()],
            [
                'status' => $status->value,
                'reason' => $reason,
                'marked_by' => $marker->id,
                'lat' => $location['lat'] ?? null,
                'lng' => $location['lng'] ?? null,
                'distance_m' => $location['distance_m'] ?? null,
                'on_site' => $location['on_site'] ?? null,
            ],
        );

        $detail = $reason ? " — {$reason}" : '';
        if ($audit) {
            $this->audit->record($marker, "Marked {$student->name} {$status->value}{$detail}", $student->public_id);
        }

        if ($notify) {
            $this->notifications->notify('attendance_manual', $student->id, [
                'date' => Carbon::parse($date ?? today())->format('d M Y'), 'status' => strtolower($status->value), 'marker' => $marker->name,
                'reason' => $reason ? ' Reason: '.$reason : '',
            ], ['url' => '/app/attendance']);
        }

        return $student->name.' marked '.strtolower($status->value).$detail;
    }

    /**
     * Marks many students the same way for one day, all or nothing. A mentor can only mark students of their own batch.
     * Each student is told (as with a single mark); the audit log gets one entry for the whole batch of marks.
     *
     * @param  list<string>  $studentPublicIds
     * @return array{marked: int, names: list<string>}
     */
    public function markMany(User $marker, array $studentPublicIds, AttendanceStatus $status, ?string $reason, ?string $date): array
    {
        $students = User::students()->whereIn('public_id', $studentPublicIds)->get();
        $missing = array_diff($studentPublicIds, $students->pluck('public_id')->all());
        if ($missing) {
            abort(404, 'Student not found: '.implode(', ', array_slice($missing, 0, 5)));
        }
        if ($marker->role === Role::Mentor) {
            $outside = $students->first(fn (User $s) => $s->batch_id !== $marker->batch_id);
            abort_if($outside, 403, "You can only mark students of your own batch ({$outside?->name} is not).");
        }

        DB::transaction(fn () => $students->each(fn (User $s) => $this->mark($marker, $s, $status, $reason, $date, audit: false)));

        $when = $date ?? Carbon::today()->toDateString();
        $this->audit->record($marker, "Marked {$students->count()} students {$status->value} in bulk", $when, $reason);

        return ['marked' => $students->count(), 'names' => $students->pluck('name')->all()];
    }
}
