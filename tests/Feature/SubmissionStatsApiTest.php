<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissionStatsApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private Batch $batch;

    private Batch $other;

    private Assessment $assignment;

    private Assessment $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->batch = Batch::create(['name' => 'Batch A', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'seats' => 20]);
        $this->other = Batch::create(['name' => 'Batch B', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'seats' => 20]);
        Course::create([
            'title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft Skills'])->id, 'hours' => 10, 'status' => 'Published',
        ])->batches()->sync([$this->batch->id, $this->other->id]);
        $this->assignment = Assessment::create([
            'type' => 'assignment', 'title' => 'Essay', 'course' => 'Communication', 'status' => 'published',
            'due_date' => now()->addDays(10)->toDateString(), 'max_marks' => 50, 'min_percent' => 60,
        ]);
        $this->quiz = Assessment::create(['type' => 'quiz', 'title' => 'Quiz', 'course' => 'Communication', 'status' => 'published']);
    }

    private function student(Batch $batch): User
    {
        return User::factory()->role(Role::Student)->create(['batch_id' => $batch->id]);
    }

    private function submission(User $student, string $status = 'submitted', int $no = 1): AssessmentSubmission
    {
        return AssessmentSubmission::create([
            'assessment_id' => $this->assignment->id, 'student_id' => $student->id, 'submission_no' => $no,
            'content' => 'answer', 'status' => $status, 'submitted_at' => now(),
        ]);
    }

    private function attempt(User $student, bool $submitted = true, int $no = 1): QuizAttempt
    {
        return QuizAttempt::create([
            'assessment_id' => $this->quiz->id, 'student_id' => $student->id, 'attempt_no' => $no, 'question_order' => [],
            'started_at' => now(), 'submitted_at' => $submitted ? now() : null,
        ]);
    }

    public function test_quiz_attempts_are_split_into_passed_not_passed_and_waiting_for_marking(): void
    {
        $me = $this->student($this->batch);
        $this->attempt($me, true, 1)->update(['passed' => true, 'result_percent' => 80]);
        $this->attempt($me, true, 2)->update(['passed' => false, 'result_percent' => 30]);
        $this->attempt($me, true, 3)->update(['needs_manual_grading' => true]);
        $this->attempt($me, false, 4); // not submitted: not counted

        Sanctum::actingAs($me);
        $this->getJson(self::BASE.'/submission-stats')->assertOk()
            ->assertJsonPath('stats.quiz.total', 3)
            ->assertJsonPath('stats.quiz.passed', 1)
            ->assertJsonPath('stats.quiz.not_passed', 1)
            ->assertJsonPath('stats.quiz.needs_review', 1);
    }

    public function test_a_student_cannot_delete_their_own_submission(): void
    {
        $student = $this->student($this->batch);
        $row = $this->submission($student);
        Sanctum::actingAs($student);

        $this->deleteJson(self::BASE."/my/assessments/{$this->assignment->id}/submissions/{$row->id}")->assertNotFound();
        $this->assertDatabaseHas('assessment_submissions', ['id' => $row->id]);
    }

    public function test_student_stats_count_only_their_own_work(): void
    {
        $me = $this->student($this->batch);
        $this->submission($me);
        $this->submission($me, 'graded', 2);
        $this->attempt($me);
        $this->attempt($me, false, 2);
        $this->submission($this->student($this->batch));
        $this->attempt($this->student($this->batch));

        Sanctum::actingAs($me);
        $this->getJson(self::BASE.'/submission-stats')->assertOk()
            ->assertJsonPath('stats.scope', 'own')
            ->assertJsonPath('stats.quiz.total', 1)
            ->assertJsonPath('stats.assignment.total', 2)
            ->assertJsonPath('stats.assignment.submitted', 1)
            ->assertJsonPath('stats.assignment.graded', 1)
            ->assertJsonPath('stats.assessment.total', 0);
    }

    public function test_mentor_sees_their_batch_and_admin_sees_everyone(): void
    {
        $mine = $this->student($this->batch);
        $theirs = $this->student($this->other);
        $this->submission($mine);
        $this->submission($theirs);
        $this->attempt($theirs);

        Sanctum::actingAs(User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]));
        $this->getJson(self::BASE.'/submission-stats')->assertOk()
            ->assertJsonPath('stats.scope', 'batch')
            ->assertJsonPath('stats.assignment.total', 1)
            ->assertJsonPath('stats.quiz.total', 0);

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->getJson(self::BASE.'/submission-stats')->assertOk()
            ->assertJsonPath('stats.scope', 'all')
            ->assertJsonPath('stats.assignment.total', 2)
            ->assertJsonPath('stats.quiz.total', 1);
    }
}
