<?php

namespace App\Services;

use App\Enums\AttendanceChoice;
use App\Enums\AttendanceRequestStatus;
use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Student Present / Absent reports and the admin's decision on them. The student reports for today; an admin (or the
 * student's mentor) marks the final status, which writes the day's attendance row.
 */
class AttendanceRequestService
{
    private const WITH = ['student:id,public_id,name,org,batch_id', 'student.batch:id,name', 'resolver:id,name'];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Students see their own reports, mentors those of their batch, staff everyone's. Pending reports come first.
     *
     * @param  array{status?: string, date?: string, requested_status?: string}  $filters
     * @return Collection<int, AttendanceRequest>
     */
    public function list(User $actor, array $filters = []): Collection
    {
        return AttendanceRequest::query()
            ->with(self::WITH)
            ->when($actor->role === Role::Student, fn (Builder $q) => $q->where('student_id', $actor->id))
            ->when($actor->role === Role::Mentor, fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('batch_id', $actor->batch_id ?? 0)))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['requested_status']), fn (Builder $q) => $q->where('requested_status', $filters['requested_status']))
            ->when(! empty($filters['date']), fn (Builder $q) => $q->whereDate('att_date', $filters['date']))
            ->orderByRaw('(status = ?) DESC', [AttendanceRequestStatus::Pending->value])
            ->orderByDesc('att_date')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();
    }

    /** The student reports for today; no location is involved. A pending report can be changed (Present ↔ Absent); a marked one is final. */
    public function submit(User $student, AttendanceChoice $choice, ?string $reason): AttendanceRequest
    {
        $today = today()->toDateString();

        $request = DB::transaction(function () use ($student, $choice, $reason, $today) {
            $existing = AttendanceRequest::where('student_id', $student->id)->whereDate('att_date', $today)->lockForUpdate()->first();

            if ($existing && ! $existing->isPending()) {
                throw ValidationException::withMessages(['status' => 'The programme office has already marked your attendance for today.']);
            }
            if (! $existing && Attendance::where('student_id', $student->id)->whereDate('att_date', $today)->exists()) {
                throw ValidationException::withMessages(['status' => 'Your attendance is already marked for today.']);
            }

            return AttendanceRequest::updateOrCreate(
                ['student_id' => $student->id, 'att_date' => $today],
                ['requested_status' => $choice, 'reason' => $choice->needsReason() ? $reason : null],
            );
        });

        $detail = $choice->needsReason() ? "{$choice->value} — {$reason}" : $choice->value;
        $this->audit->record($student, 'Sent attendance report', $student->public_id, $detail);
        $this->notifications->notifyStaff('attendance_reported', $student->batch_id, [
            'student' => $student->name, 'batch' => $student->batch?->name ?? 'no batch', 'status' => strtolower($choice->value),
            'date' => $request->att_date->format('d M Y'), 'reason' => $choice->needsReason() && $reason ? ' Reason: '.$reason : '',
        ], ['url' => '/app/attendance']);

        return $this->load($request);
    }

    /**
     * Mark the final status. Present when the student reported Present is a plain Present; any other present is a
     * Manual mark. Marking again (admin changed their mind) simply rewrites the day's row.
     */
    public function mark(User $actor, AttendanceRequest $request, AttendanceChoice $decision, ?string $note): AttendanceRequest
    {
        DB::transaction(function () use ($actor, &$request, $decision, $note) {
            $request = AttendanceRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $student = $request->student;

            abort_if($actor->role === Role::Mentor && $student->batch_id !== $actor->batch_id, 403, 'You can only mark students of your own batch.');

            [$status, $reason] = match (true) {
                $decision === AttendanceChoice::Absent => [AttendanceStatus::Absent, $note ?: $request->reason ?: "Marked absent by {$actor->name}"],
                $request->requested_status === AttendanceChoice::Present => [AttendanceStatus::Present, null],
                default => [AttendanceStatus::Manual, $note ?: "Marked present by {$actor->name} (student reported absent: {$request->reason})"],
            };
            $this->attendance->mark($actor, $student, $status, $reason, $request->att_date->toDateString(), notify: false); // 'attendance_marked' below tells the student

            $request->update([
                'status' => AttendanceRequestStatus::Resolved,
                'decision' => $decision,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);
        });

        $this->notifications->notify('attendance_marked', $request->student_id, [
            'date' => $request->att_date->format('d M Y'), 'status' => strtolower($decision->value), 'marker' => $actor->name,
        ], ['url' => '/app/attendance']);

        return $this->load($request);
    }

    private function load(AttendanceRequest $request): AttendanceRequest
    {
        return $request->refresh()->load(self::WITH);
    }
}
