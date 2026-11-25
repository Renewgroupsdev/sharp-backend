<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\LearningProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgrammeOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_see_the_programme_numbers_and_others_are_refused(): void
    {
        $batch = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $admin = User::factory()->role(Role::Admin)->create();
        User::factory()->role(Role::Mentor)->create(['batch_id' => $batch->id]);
        [$a, $b, $c] = User::factory()->count(3)->create(['batch_id' => $batch->id])->all();
        $course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        Course::create(['title' => 'Draft one', 'course_category_id' => $course->course_category_id, 'hours' => 2, 'status' => 'Draft']);

        Attendance::create(['student_id' => $a->id, 'att_date' => today(), 'status' => AttendanceStatus::Present->value, 'marked_by' => $admin->id]);
        Attendance::create(['student_id' => $b->id, 'att_date' => today(), 'status' => AttendanceStatus::Absent->value, 'reason' => 'ill', 'marked_by' => $admin->id]);
        LearningProgress::create(['user_id' => $a->id, 'course_id' => $course->id, 'progress' => 100, 'materials_completed' => 4, 'last_accessed_at' => now()]);
        LearningProgress::create(['user_id' => $b->id, 'course_id' => $course->id, 'progress' => 50, 'materials_completed' => 2, 'last_accessed_at' => now()->subDays(20)]);
        Certificate::create(['user_id' => $a->id, 'course_id' => $course->id]);

        Sanctum::actingAs($admin);
        $o = $this->getJson('/api/web/dashboard/programme')->assertOk()->json('overview');

        $this->assertSame(3, $o['students']);
        $this->assertSame(1, $o['mentors']);
        $this->assertSame(['published' => 1, 'total' => 2], $o['courses']);
        $this->assertSame(75, $o['learning']['avg_progress']);
        $this->assertSame(1, $o['learning']['active_7d']);
        $this->assertSame(1, $o['learning']['courses_completed']);
        $this->assertSame(['present' => 1, 'absent' => 1, 'not_marked' => 1, 'rate' => 50], array_intersect_key($o['attendance'], array_flip(['present', 'absent', 'not_marked', 'rate'])));
        $this->assertSame(1, $o['certificates']['pending']);

        Sanctum::actingAs($c);
        $this->getJson('/api/web/dashboard/programme')->assertForbidden();
    }
}
