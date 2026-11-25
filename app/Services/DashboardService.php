<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\CommonFloorMessage;
use App\Models\User;

class DashboardService
{
    public function stats(): array
    {
        $byStatus = User::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $today = Attendance::query()
            ->whereDate('att_date', today())
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $marked = (int) $today->sum();
        $present = (int) ($today[AttendanceStatus::Present->value] ?? 0) + (int) ($today[AttendanceStatus::Manual->value] ?? 0);

        return [
            'colleges' => College::count(),
            'users_by_role' => User::query()->selectRaw('role, COUNT(*) AS n')->groupBy('role')->pluck('n', 'role'),
            'active_users' => (int) ($byStatus[UserStatus::Active->value] ?? 0),
            'suspended_users' => (int) ($byStatus[UserStatus::Suspended->value] ?? 0),
            'invited_users' => (int) ($byStatus[UserStatus::Invited->value] ?? 0),
            'today_attendance_rate' => $marked ? (int) round($present / $marked * 100) : null,
            'today_marked' => $marked,
            'common_floor_24h' => CommonFloorMessage::visible()->where('created_at', '>', now()->subDay())->count(),
            'flagged_messages' => CommonFloorMessage::visible()->where('flagged', true)->count(),
            'recent_activity' => AuditLog::with('actor:id,name')->latest('created_at')->latest('id')->limit(8)->get(),
        ];
    }
}
