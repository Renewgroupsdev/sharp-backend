<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\LearningProgress;
use App\Models\Notification;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrollmentExtrasApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private Course $course;

    private Batch $batch;

    private User $priya;

    private User $arun;

    private User $mentor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published', 'mentors' => ['Mentor']]);
        $this->course->batches()->sync([$this->batch->id]);

        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $this->batch->id]);
        $this->arun = User::factory()->create(['name' => 'Arun', 'batch_id' => $this->batch->id]);
        $this->mentor = User::factory()->role(Role::Mentor)->create(['name' => 'Mentor', 'batch_id' => $this->batch->id]);
        $this->admin = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);

        Sanctum::actingAs($this->admin);
    }

    private function enrollmentId(): int
    {
        return $this->getJson(self::BASE.'/enrollments')->json('enrollments.0.id');
    }

    private function otherBatch(string $name = 'Batch 2'): Batch
    {
        return Batch::create(['name' => $name, 'start_date' => today()->addMonth(), 'end_date' => today()->addMonths(4), 'seats' => 20, 'status' => 'enrolling']);
    }

    public function test_detail_shows_each_students_progress_attendance_quiz_and_certificate(): void
    {
        LearningProgress::create(['user_id' => $this->priya->id, 'course_id' => $this->course->id, 'progress' => 100, 'materials_completed' => 4]);
        LearningProgress::create(['user_id' => $this->arun->id, 'course_id' => $this->course->id, 'progress' => 40, 'materials_completed' => 2]);
        Attendance::create(['student_id' => $this->priya->id, 'att_date' => today(), 'status' => 'Present']);
        Attendance::create(['student_id' => $this->priya->id, 'att_date' => today()->subDay(), 'status' => 'Absent', 'reason' => 'x']);
        $quiz = Assessment::create(['type' => 'quiz', 'title' => 'Q1', 'course' => 'Communication', 'module' => 'M1', 'topic' => 'All', 'status' => 'published']);
        QuizResult::create(['assessment_id' => $quiz->id, 'student_id' => $this->priya->id, 'marks' => 8, 'total' => 10, 'result_percent' => 80, 'attempts' => 1]);
        Certificate::create(['user_id' => $this->priya->id, 'course_id' => $this->course->id]);

        $res = $this->getJson(self::BASE.'/enrollments/'.$this->enrollmentId().'/detail')->assertOk()
            ->assertJsonPath('enrollment.course', 'Communication')
            ->assertJsonPath('summary.students', 2)->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.avg_progress', 70)->assertJsonPath('summary.avg_quiz', 80)
            ->assertJsonPath('summary.certificates_pending', 1)
            ->assertJsonCount(2, 'students')->assertJsonCount(1, 'mentors');

        $priya = collect($res->json('students'))->firstWhere('name', 'Priya');
        $this->assertSame(100, $priya['progress']);
        $this->assertSame(50, $priya['attendance_rate']);
        $this->assertSame(80, $priya['quiz_avg']);
        $this->assertSame('Pending', $priya['certificate']['status']);
        $arun = collect($res->json('students'))->firstWhere('name', 'Arun');
        $this->assertNull($arun['attendance_rate']);
        $this->assertNull($arun['certificate']);
    }

    public function test_enrolling_writes_history_and_notifies_the_batch(): void
    {
        $other = $this->otherBatch();
        $student = User::factory()->create(['batch_id' => $other->id]);
        $teacher = User::factory()->role(Role::Mentor)->create(['batch_id' => $other->id]);

        $this->postJson(self::BASE.'/enrollments', ['course_id' => $this->course->id, 'batch_ids' => [$other->id]])->assertCreated();

        $this->assertDatabaseHas('enrollment_events', ['course_id' => $this->course->id, 'batch_id' => $other->id, 'event' => 'Enrolled', 'source' => 'manual', 'actor_id' => $this->admin->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'type' => 'enrollment', 'title' => 'New course added']);
        $this->assertDatabaseHas('notifications', ['user_id' => $teacher->id, 'title' => 'You are teaching a new course']);
        $this->assertSame(0, Notification::where('user_id', $this->priya->id)->count()); // another batch

        // Enrolling again changes nothing: no second event, no second notification.
        $this->postJson(self::BASE.'/enrollments', ['course_id' => $this->course->id, 'batch_ids' => [$other->id]])->assertCreated();
        $this->assertSame(1, Notification::where('user_id', $student->id)->count());

        $id = collect($this->getJson(self::BASE.'/enrollments')->json('enrollments'))->firstWhere('batch', 'Batch 2')['id'];
        $this->deleteJson(self::BASE."/enrollments/{$id}")->assertOk();
        $this->assertDatabaseHas('enrollment_events', ['batch_id' => $other->id, 'event' => 'Removed']);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'title' => 'Course removed']);

        $this->getJson(self::BASE.'/enrollments/history?batch_id='.$other->id)->assertOk()
            ->assertJsonCount(2, 'history')->assertJsonPath('history.0.event', 'Removed')->assertJsonPath('history.0.actor', 'Admin');
    }

    public function test_mapping_changes_made_in_the_course_form_are_recorded_too(): void
    {
        $other = $this->otherBatch();

        $this->putJson(self::BASE.'/courses/'.$this->course->id, ['title' => 'Communication', 'course_category_id' => $this->course->course_category_id, 'hours' => 6, 'status' => 'Published', 'batch_ids' => [$other->id]])->assertOk();

        $this->assertDatabaseHas('enrollment_events', ['batch_id' => $other->id, 'event' => 'Enrolled', 'source' => 'course form']);
        $this->assertDatabaseHas('enrollment_events', ['batch_id' => $this->batch->id, 'event' => 'Removed', 'source' => 'course form']);
    }

    public function test_bulk_enroll_copy_and_remove_many(): void
    {
        $b2 = $this->otherBatch('Batch 2');
        $b3 = $this->otherBatch('Batch 3');
        $second = Course::create(['title' => 'Aptitude', 'course_category_id' => $this->course->course_category_id, 'hours' => 2]);

        $this->postJson(self::BASE.'/enrollments/bulk', ['course_ids' => [$this->course->id, $second->id], 'batch_ids' => [$b2->id, $b3->id]])
            ->assertCreated()->assertJsonCount(4, 'enrollments');
        $this->assertDatabaseCount('batch_course', 5);

        // Copy the two enrollments of Batch 2 to a new batch.
        $b4 = $this->otherBatch('Batch 4');
        $ids = collect($this->getJson(self::BASE.'/enrollments')->json('enrollments'))->where('batch', 'Batch 2')->pluck('id')->all();
        $this->postJson(self::BASE.'/enrollments/copy', ['enrollment_ids' => $ids, 'batch_ids' => [$b4->id]])->assertOk()->assertJsonPath('created', 2);
        $this->assertDatabaseHas('enrollment_events', ['batch_id' => $b4->id, 'source' => 'copy']);

        $remove = collect($this->getJson(self::BASE.'/enrollments')->json('enrollments'))->whereIn('batch', ['Batch 3', 'Batch 4'])->pluck('id')->all();
        $this->postJson(self::BASE.'/enrollments/remove', ['ids' => $remove])->assertOk()->assertJsonPath('removed', 4);
        $this->assertDatabaseCount('batch_course', 3);

        $this->postJson(self::BASE.'/enrollments/bulk', ['course_ids' => [], 'batch_ids' => [$b2->id]])->assertUnprocessable();
    }

    public function test_import_previews_then_enrols_and_reports_bad_rows(): void
    {
        $b2 = $this->otherBatch('Batch 2');
        $rows = [
            ['course' => 'communication', 'batch' => 'batch 2'],        // by title, any case
            ['course' => $this->course->code(), 'batch' => 'Batch 1'],  // already enrolled
            ['course' => 'Nope', 'batch' => 'Batch 2'],
            ['course' => 'Communication', 'batch' => 'Nope'],
            ['course' => 'Communication', 'batch' => 'Batch 2'],        // repeated in the file
            ['course' => '', 'batch' => 'Batch 2'],
        ];

        $preview = $this->postJson(self::BASE.'/enrollments/import', ['rows' => $rows, 'dry_run' => true])->assertOk()
            ->assertJsonPath('dry_run', true)->assertJsonPath('summary.ok', 1)->assertJsonPath('summary.exists', 2)->assertJsonPath('summary.error', 3);
        $this->assertSame('Unknown course “Nope”', $preview->json('rows.2.message'));
        $this->assertDatabaseCount('batch_course', 1);

        $this->postJson(self::BASE.'/enrollments/import', ['rows' => $rows])->assertOk()->assertJsonPath('summary.ok', 1);
        $this->assertDatabaseHas('batch_course', ['course_id' => $this->course->id, 'batch_id' => $b2->id]);
        $this->assertDatabaseHas('enrollment_events', ['batch_id' => $b2->id, 'source' => 'import']);

        $this->postJson(self::BASE.'/enrollments/import', ['rows' => []])->assertUnprocessable();
    }

    public function test_finishing_a_course_requests_a_certificate_and_an_admin_issues_it(): void
    {
        $chapter = $this->course->chapters()->create(['name' => 'M1', 'status' => 'Published', 'position' => 0]);
        $lesson = $chapter->lessons()->create(['name' => 'L1', 'duration_minutes' => 5, 'host' => 'Editor', 'content' => 'x', 'privacy' => 'Enrolled students only']);

        Sanctum::actingAs($this->priya);
        $this->postJson(self::BASE."/my/lessons/{$lesson->id}/complete")->assertOk()->assertJsonPath('progress.progress', 100);
        $this->assertDatabaseHas('certificates', ['user_id' => $this->priya->id, 'course_id' => $this->course->id, 'status' => 'Pending']);

        Sanctum::actingAs($this->mentor);
        $cert = Certificate::first();
        $this->getJson(self::BASE.'/certificates')->assertOk()->assertJsonCount(1, 'certificates');
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson(self::BASE."/certificates/{$cert->id}/issue")->assertOk()
            ->assertJsonPath('certificate.status', 'Issued')->assertJsonPath('certificate.name', 'Priya');
        $this->assertStringStartsWith('SHARP-', Certificate::first()->number);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->priya->id, 'type' => 'certificate', 'title' => 'Your certificate is ready']);

        $this->postJson(self::BASE."/certificates/{$cert->id}/reject")->assertUnprocessable();
    }

    public function test_generating_certificates_covers_only_finished_students_without_one(): void
    {
        LearningProgress::create(['user_id' => $this->priya->id, 'course_id' => $this->course->id, 'progress' => 100, 'materials_completed' => 1]);
        LearningProgress::create(['user_id' => $this->arun->id, 'course_id' => $this->course->id, 'progress' => 60, 'materials_completed' => 1]);
        $id = $this->enrollmentId();

        $this->postJson(self::BASE."/enrollments/{$id}/certificates")->assertOk()->assertJsonPath('created', 1);
        $this->postJson(self::BASE."/enrollments/{$id}/certificates")->assertOk()->assertJsonPath('created', 0);

        $cert = Certificate::first();
        $this->postJson(self::BASE."/certificates/{$cert->id}/reject")->assertOk()->assertJsonPath('certificate.status', 'Rejected');
        $this->getJson(self::BASE.'/certificates?status=Rejected')->assertJsonCount(1, 'certificates');
        $this->getJson(self::BASE.'/certificates?status=Issued')->assertJsonCount(0, 'certificates');
    }

    public function test_each_user_reads_and_clears_only_their_own_inbox(): void
    {
        $other = $this->otherBatch();
        $mine = User::factory()->create(['batch_id' => $other->id]);
        $theirs = User::factory()->create(['batch_id' => $other->id]);
        $this->postJson(self::BASE.'/enrollments', ['course_id' => $this->course->id, 'batch_ids' => [$other->id]])->assertCreated();

        Sanctum::actingAs($mine);
        $list = $this->getJson(self::BASE.'/notifications')->assertOk()->assertJsonPath('unread', 1)->assertJsonCount(1, 'notifications');
        $id = $list->json('notifications.0.id');
        $this->assertFalse($list->json('notifications.0.read'));
        $this->assertSame('enrollment', $list->json('notifications.0.type'));

        Sanctum::actingAs($theirs);
        $this->postJson(self::BASE."/notifications/{$id}/read")->assertNotFound(); // not theirs

        Sanctum::actingAs($mine);
        $this->postJson(self::BASE."/notifications/{$id}/read")->assertOk()->assertJsonPath('unread', 0);
        $this->getJson(self::BASE.'/notifications')->assertJsonPath('notifications.0.read', true);

        Sanctum::actingAs($theirs);
        $this->getJson(self::BASE.'/notifications')->assertJsonPath('unread', 1);
        $this->postJson(self::BASE.'/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
    }

    public function test_mentors_see_history_of_their_batch_only_and_cannot_write(): void
    {
        $other = $this->otherBatch();
        $this->postJson(self::BASE.'/enrollments', ['course_id' => $this->course->id, 'batch_ids' => [$other->id]])->assertCreated();

        Sanctum::actingAs($this->mentor);
        $this->getJson(self::BASE.'/enrollments/history')->assertOk()->assertJsonCount(0, 'history');
        $this->postJson(self::BASE.'/enrollments/bulk', ['course_ids' => [$this->course->id], 'batch_ids' => [$other->id]])->assertForbidden();
        $this->postJson(self::BASE.'/enrollments/import', ['rows' => [['course' => 'a', 'batch' => 'b']]])->assertForbidden();
        $this->getJson(self::BASE.'/enrollments/'.collect($this->getJson(self::BASE.'/enrollments')->json('enrollments'))->first()['id'].'/detail')->assertOk();
    }
}
