<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssignmentSubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    private Batch $batch;
    private Course $course;
    private Assessment $assignment;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->batch = Batch::create(['name' => 'Batch A', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'seats' => 20]);
        $this->course = Course::create([
            'title' => 'Communication',
            'course_category_id' => CourseCategory::create(['name' => 'Soft Skills'])->id,
            'hours' => 10,
            'status' => 'Published',
        ]);
        $this->course->batches()->sync([$this->batch->id]);
        $this->student = User::factory()->role(Role::Student)->create(['batch_id' => $this->batch->id]);
        $this->assignment = Assessment::create([
            'type' => 'assignment', 'title' => 'Communication Essay', 'course' => 'Communication',
            'status' => 'published', 'due_date' => now()->addDays(10)->toDateString(), 'max_marks' => 50, 'min_percent' => 60,
        ]);
        Sanctum::actingAs($this->student);
    }

    public function test_student_can_submit_written_work_and_multiple_attachments(): void
    {
        $response = $this->post('/api/web/my/assessments/'.$this->assignment->id.'/submissions', [
            'content' => 'My essay answer',
            'files' => [UploadedFile::fake()->create('essay.pdf', 120, 'application/pdf'), UploadedFile::fake()->image('diagram.png')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('submission.submission_no', 1)->assertJsonPath('submission.status', 'submitted')->assertJsonCount(2, 'submission.attachments');
        $this->assertDatabaseHas('assessment_submissions', ['assessment_id' => $this->assignment->id, 'student_id' => $this->student->id, 'submission_no' => 1, 'content' => 'My essay answer']);
        $this->assertDatabaseCount('submission_attachments', 2);
    }

    public function test_student_can_resubmit_until_due_and_versions_are_preserved(): void
    {
        $this->postJson('/api/web/my/assessments/'.$this->assignment->id.'/submissions', ['content' => 'Version one'])->assertCreated();
        $first = \App\Models\AssessmentSubmission::firstOrFail();
        $this->actingAs(User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]));
        $this->postJson('/api/web/submissions/'.$first->id.'/grade', ['marks_obtained' => 20, 'feedback' => 'Please improve', 'status' => 'returned'])->assertOk();
        $this->actingAs($this->student);
        $this->postJson('/api/web/my/assessments/'.$this->assignment->id.'/submissions', ['content' => 'Version two'])->assertCreated()->assertJsonPath('submission.submission_no', 2);
        $this->assertDatabaseCount('assessment_submissions', 2);
    }

    public function test_mentor_can_grade_only_students_in_their_own_batch(): void
    {
        $this->postJson('/api/web/my/assessments/'.$this->assignment->id.'/submissions', ['content' => 'Answer'])->assertCreated();
        $submission = \App\Models\AssessmentSubmission::firstOrFail();
        $mentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]);
        Sanctum::actingAs($mentor);
        $this->postJson('/api/web/submissions/'.$submission->id.'/grade', ['marks_obtained' => 42, 'feedback' => 'Good work'])->assertOk()
            ->assertJsonPath('submission.status', 'graded')->assertJsonPath('submission.marks_obtained', '42.00');

        $otherBatch = Batch::create(['name' => 'Batch B', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'seats' => 20]);
        $otherMentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $otherBatch->id]);
        Sanctum::actingAs($otherMentor);
        $this->postJson('/api/web/submissions/'.$submission->id.'/grade', ['marks_obtained' => 10])->assertForbidden();
    }

    public function test_marks_cannot_exceed_the_assessment_total(): void
    {
        $this->postJson('/api/web/my/assessments/'.$this->assignment->id.'/submissions', ['content' => 'Answer'])->assertCreated();
        $submission = \App\Models\AssessmentSubmission::firstOrFail();
        Sanctum::actingAs(User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]));
        $this->postJson('/api/web/submissions/'.$submission->id.'/grade', ['marks_obtained' => 51])->assertUnprocessable()->assertJsonValidationErrors(['marks_obtained']);
    }
}
