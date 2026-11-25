<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Students whose attendance for today is still pending: no report from the student and no mark from staff. The programme
 * office (Super Admin, Admin, OB) gets one summary; each batch's mentors get the count for their own batch.
 */
class NotifyPendingAttendance extends Command
{
    protected $signature = 'attendance:notify-pending';

    protected $description = "Tell the office and each batch's mentors which students still have no attendance for today";

    public function handle(NotificationService $notifications): int
    {
        $today = today()->toDateString();

        $pending = User::query()->with('batch:id,name')
            ->where('role', Role::Student->value)->where('status', UserStatus::Active->value)
            ->whereNotIn('id', Attendance::query()->whereDate('att_date', $today)->select('student_id'))
            ->whereNotIn('id', AttendanceRequest::query()->whereDate('att_date', $today)->select('student_id'))
            ->get(['id', 'batch_id']);

        if ($pending->isEmpty()) {
            $this->info('Nobody is pending today.');

            return self::SUCCESS;
        }

        $date = today()->format('d M Y');
        $data = ['url' => '/app/attendance'];

        $staff = User::query()->where('status', '<>', UserStatus::Suspended->value)
            ->whereIn('role', array_map(fn (Role $r) => $r->value, Role::staff()))->pluck('id')->all();
        $notifications->notify('attendance_pending', $staff, ['count' => $pending->count(), 'scope' => 'across all batches', 'date' => $date], $data);

        foreach ($pending->groupBy('batch_id') as $batchId => $students) {
            if (! $batchId) {
                continue;
            }
            $mentors = User::where('role', Role::Mentor->value)->where('batch_id', $batchId)->where('status', '<>', UserStatus::Suspended->value)->pluck('id')->all();
            $notifications->notify('attendance_pending', $mentors, ['count' => $students->count(), 'scope' => 'in '.$students->first()->batch->name, 'date' => $date], $data);
        }

        $this->info("{$pending->count()} student(s) pending.");

        return self::SUCCESS;
    }
}
