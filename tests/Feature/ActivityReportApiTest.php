<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ActivityReportService;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_actions_are_grouped_by_kind_day_and_person_for_staff_only(): void
    {
        $admin = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);
        $mentor = User::factory()->role(Role::Mentor)->create(['name' => 'Mentor']);
        $log = app(AuditLogger::class);
        $log->record($admin, 'Issued certificate', 'C-1');
        $log->record($admin, 'Cancelled certificate', 'C-2');
        $log->record($mentor, 'Graded assignment', '5');
        $log->record($mentor, 'Marked Priya Present', 'SH1');
        $log->record($admin, 'Created user SH2 (student) — username and temporary password issued', 'SH2');
        $log->record($admin, 'Something unusual');
        AuditLog::query()->where('action', 'Something unusual')->toBase()->update(['created_at' => now()->subDays(30)]); // outside the 14-day window

        Sanctum::actingAs($admin);
        $report = $this->getJson('/api/web/dashboard/activity?days=14')->assertOk()->json('activity');

        $this->assertSame(14, count($report['by_day']));
        $this->assertSame(5, $report['total']);
        $kinds = array_column($report['kinds'], 'count', 'name');
        $this->assertSame(['Certificates' => 2, 'Assessments' => 1, 'Attendance' => 1, 'Users & access' => 1], array_filter($kinds));
        $this->assertSame(5, end($report['by_day'])['total']);
        $this->assertSame(['Admin' => 3, 'Mentor' => 2], array_column($report['people'], 'count', 'name'));
        $this->assertCount(6, $report['recent']); // the latest actions, whatever the window
        $this->assertSame(ActivityReportService::kinds(), array_column($report['kinds'], 'name'));

        Sanctum::actingAs($mentor);
        $this->getJson('/api/web/dashboard/activity')->assertForbidden();
    }

    public function test_the_kind_of_an_action_follows_its_wording(): void
    {
        $this->assertSame('Notifications', ActivityReportService::kindOf('Sent a notification'));
        $this->assertSame('Community', ActivityReportService::kindOf('Removed a community message'));
        $this->assertSame('Learning', ActivityReportService::kindOf('Unlocked a module early'));
        $this->assertSame('Users & access', ActivityReportService::kindOf('Reset password for Arun'));
        $this->assertSame('Other', ActivityReportService::kindOf('Backed up the database'));
    }
}
