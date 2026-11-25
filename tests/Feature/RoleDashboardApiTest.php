<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\College;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\LearningProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Each role gets its own figures, limited to what that role may see. */
class RoleDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/web/my/dashboard';

    private Batch $one;

    private Batch $two;

    private College $fatima;

    private College $other;

    private Course $course;

    private User $priya;

    private User $arun;

    private User $meena;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fatima = College::create(['name' => 'Fatima College', 'code' => 'FCM']);
        $this->other = College::create(['name' => 'Other College', 'code' => 'OTH']);
        $this->one = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 10, 'status' => 'running']);
        $this->two = Batch::create(['name' => 'Batch 2', 'start_date' => today()->addDays(10), 'end_date' => today()->addMonths(3), 'seats' => 10, 'status' => 'enrolling']);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        $this->course->batches()->sync([$this->one->id]);

        // Priya and Meena: Fatima College, Batch 1. Arun: another college, Batch 2.
        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $this->one->id, 'college_id' => $this->fatima->id]);
        $this->meena = User::factory()->create(['name' => 'Meena', 'batch_id' => $this->one->id, 'college_id' => $this->fatima->id]);
        $this->arun = User::factory()->create(['name' => 'Arun', 'batch_id' => $this->two->id, 'college_id' => $this->other->id]);

        foreach ([[$this->priya, 100, 9, 1], [$this->meena, 20, 2, 8]] as [$s, $progress, $present, $absent]) {
            LearningProgress::create(['user_id' => $s->id, 'course_id' => $this->course->id, 'progress' => $progress, 'materials_completed' => 1, 'last_accessed_at' => now()]);
            for ($i = 0; $i < $present + $absent; $i++) {
                Attendance::create(['student_id' => $s->id, 'att_date' => today()->subDays($i), 'status' => $i < $present ? AttendanceStatus::Present->value : AttendanceStatus::Absent->value, 'marked_by' => $s->id, 'reason' => $i < $present ? null : 'x']);
            }
        }
        Assessment::create(['type' => 'assignment', 'title' => 'Pitch', 'course' => 'Communication', 'status' => 'published', 'due_date' => today()->addDays(2), 'max_marks' => 10]);
    }

    private function dash(User $as): array
    {
        Sanctum::actingAs($as);

        return $this->getJson(self::URL)->assertOk()->json('dashboard');
    }

    private function kpi(array $dash, string $label)
    {
        return collect($dash['kpis'])->firstWhere(0, $label)[1] ?? null;
    }

    private function block(array $dash, string $title): array
    {
        return collect($dash['blocks'])->firstWhere('title', $title) ?? $this->fail("No block called {$title}");
    }

    public function test_staff_get_programme_figures_and_only_the_super_admin_gets_the_system_blocks(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $super = User::factory()->role(Role::SuperAdmin)->create();

        $d = $this->dash($admin);
        $this->assertSame(3, $this->kpi($d, 'Students'));
        $this->assertSame(1, $this->kpi($d, 'Courses live'));
        $titles = array_column($d['blocks'], 'title');
        $this->assertContains('Attendance today', $titles);
        $this->assertContains('Certificate pipeline', $titles);
        $this->assertNotContains('Accounts by status', $titles);
        $this->assertNotContains('Latest actions', $titles);

        $s = $this->dash($super);
        $this->assertContains('Accounts by status', array_column($s['blocks'], 'title'));
        $this->assertContains('Latest actions', array_column($s['blocks'], 'title'));
    }

    public function test_the_observer_sees_programme_results(): void
    {
        $d = $this->dash(User::factory()->role(Role::Ob)->create());

        $this->assertSame('Programme results', $d['title']);
        $this->assertSame(2, $this->kpi($d, 'Active batches'));
        $this->assertSame('60%', $this->kpi($d, 'Avg progress')); // (100 + 20) / 2
        $this->assertSame('55%', $this->kpi($d, 'Avg attendance')); // 11 present of 20 marked
        $this->assertContains('Quiz results', array_column($d['blocks'], 'title'));
    }

    public function test_an_institution_sees_only_its_own_colleges_students(): void
    {
        $inst = User::factory()->role(Role::Institution)->create(['college_id' => $this->fatima->id, 'org' => 'Fatima College']);

        $d = $this->dash($inst);
        $this->assertSame(2, $this->kpi($d, 'Students')); // Priya and Meena, not Arun
        $this->assertSame(1, $this->kpi($d, 'At risk')); // Meena: 2 of 10 days present
        $follow = $this->block($d, 'Students to follow up')['items'];
        $this->assertStringContainsString('Meena', $follow[0]['text']);
        $this->assertNotContains('Arun', array_column($follow, 'text'));
    }

    public function test_a_member_sees_the_students_taught_by_its_organisations_mentors(): void
    {
        User::factory()->role(Role::Mentor)->create(['name' => 'Industry Mentor', 'org' => 'Sundaram Textiles', 'batch_id' => $this->one->id]);
        User::factory()->role(Role::Mentor)->create(['name' => 'Someone Else', 'org' => 'Other Co', 'batch_id' => $this->two->id]);
        $member = User::factory()->role(Role::Member)->create(['org' => 'Sundaram Textiles']);
        $stranger = User::factory()->role(Role::Member)->create(['org' => 'No Mentors Ltd']);

        $d = $this->dash($member);
        $this->assertSame(2, $this->kpi($d, 'Students')); // Batch 1 only
        $this->assertSame(1, $this->kpi($d, 'Mentors'));

        $empty = $this->dash($stranger);
        $this->assertSame(0, $this->kpi($empty, 'Students'));
        $this->assertNotNull($empty['note']);
    }

    public function test_a_mentor_sees_only_their_own_batch(): void
    {
        $mentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $this->one->id]);

        $d = $this->dash($mentor);
        $this->assertSame(2, $this->kpi($d, 'My students'));
        $today = $this->block($d, 'Attendance today');
        $this->assertSame(['Present' => 2, 'Absent' => 0, 'Not marked' => 0], collect($today['slices'])->pluck('value', 'label')->all());
        $this->assertContains('Pitch', array_column($this->block($d, 'Upcoming deadlines')['items'], 'text'));
        $behind = array_column($this->block($d, 'Students behind')['items'], 'text');
        $this->assertStringContainsString('Meena', $behind[0]);
    }

    public function test_a_student_sees_their_own_courses_deadlines_and_eligibility(): void
    {
        $d = $this->dash($this->meena);

        $this->assertSame(1, $this->kpi($d, 'Courses'));
        $this->assertSame('20%', $this->kpi($d, 'Overall progress'));
        $this->assertSame('20%', $this->kpi($d, 'Attendance'));
        $this->assertSame(1, $this->kpi($d, 'Due this week'));
        $due = $this->block($d, 'Due soon')['items'];
        $this->assertSame('Pitch', $due[0]['text']);
        $this->assertSame('Communication', $this->block($d, 'My courses')['items'][0]['label']);
        $this->assertSame(20, $this->block($d, 'Certificate eligibility')['items'][0]['value']);

        // A student outside any course sees an empty dashboard, not someone else's numbers.
        $none = User::factory()->create(['batch_id' => $this->two->id]);
        $this->assertSame(0, $this->kpi($this->dash($none), 'Courses'));
    }
}
