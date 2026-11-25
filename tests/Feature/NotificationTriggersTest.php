<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Notification;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\AttendanceService;
use App\Services\CertificateService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The actions that notify people: who is told, and that nobody is told about their own action. */
class NotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $mentor;

    private User $priya;

    private User $arun;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $one = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $two = Batch::create(['name' => 'Batch 2', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        $this->course->batches()->sync([$one->id]);

        $this->admin = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);
        $this->mentor = User::factory()->role(Role::Mentor)->create(['name' => 'Mentor', 'batch_id' => $one->id]);
        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $one->id]);
        $this->arun = User::factory()->create(['name' => 'Arun', 'batch_id' => $two->id]);
    }

    private function titles(User $user): array
    {
        return Notification::where('user_id', $user->id)->orderBy('id')->pluck('title')->all();
    }

    public function test_marking_attendance_by_hand_tells_the_student(): void
    {
        app(AttendanceService::class)->mark($this->mentor, $this->priya, AttendanceStatus::Present, null, null);

        $this->assertSame(['Attendance marked present'], $this->titles($this->priya));
        $this->assertSame([], $this->titles($this->mentor));
    }

    public function test_publishing_an_assessment_tells_the_students_of_the_course_only(): void
    {
        $data = ['type' => 'assignment', 'title' => 'Pitch', 'course' => 'Communication', 'due_date' => '2030-01-01', 'max_marks' => 10, 'status' => 'published'];
        $assessment = app(AssessmentService::class)->save($this->admin, $data);

        $this->assertSame(['New assignment: Pitch'], $this->titles($this->priya));
        $this->assertSame([], $this->titles($this->arun)); // a batch that does not take the course

        app(AssessmentService::class)->save($this->admin, ['title' => 'Pitch v2'] + $data, $assessment); // already published: no second notice
        $this->assertCount(1, $this->titles($this->priya));
    }

    public function test_a_certificate_request_tells_the_office_and_the_batch_mentor_once(): void
    {
        $service = app(CertificateService::class);
        $service->request($this->priya, $this->course->id);
        $service->request($this->priya, $this->course->id); // asking again changes nothing

        $this->assertSame(['Priya requested a certificate'], $this->titles($this->admin));
        $this->assertSame(['Priya requested a certificate'], $this->titles($this->mentor));
        $this->assertSame([], $this->titles($this->priya));
    }

    public function test_creating_a_user_tells_the_rest_of_the_office_and_a_password_reset_tells_the_person(): void
    {
        $other = User::factory()->role(Role::Ob)->create(['name' => 'OB']);
        [$created] = app(UserService::class)->create($this->admin, Role::Mentor, ['name' => 'New Mentor', 'username' => 'new.mentor']);

        $this->assertSame(['New user: New Mentor'], $this->titles($other));
        $this->assertSame([], $this->titles($this->admin)); // not the person who did it

        app(UserService::class)->resetPassword($this->admin, $this->priya);
        $this->assertSame(['Your password was reset'], $this->titles($this->priya));
    }

    public function test_community_groups_tell_new_members_and_announcements_tell_the_group(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/web/community/groups', ['name' => 'Notices', 'kind' => 'Announcement', 'members' => [$this->priya->public_id]])->assertCreated()->json('group.id');
        $this->assertSame(['You were added to Notices'], $this->titles($this->priya));
        $this->assertSame([], $this->titles($this->arun));

        $this->postJson("/api/web/community/groups/{$id}/messages", ['body' => 'Holiday tomorrow'])->assertCreated();
        $this->assertSame(['You were added to Notices', 'Notices: new announcement'], $this->titles($this->priya));
        $this->assertSame([], $this->titles($this->arun));
        $this->assertSame([], $this->titles($this->admin)); // the author is not told about their own post

        // Editing the group tells only the people who were not in it before.
        $this->putJson("/api/web/community/groups/{$id}", ['members' => [$this->priya->public_id, $this->arun->public_id]])->assertOk();
        $this->assertCount(2, $this->titles($this->priya));
        $this->assertSame(['You were added to Notices'], $this->titles($this->arun));
    }
}
