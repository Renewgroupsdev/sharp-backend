<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Batch;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizAttemptApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web/quiz-attempts';

    private Batch $mine;

    private User $mentor;

    private User $priya;

    private User $arun;

    private Assessment $quiz;

    private Question $q;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Batch::create(['name' => 'Mine', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $other = Batch::create(['name' => 'Other', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->mentor = User::factory()->create(['role' => 'mentor', 'batch_id' => $this->mine->id]);
        $this->priya = User::factory()->create(['batch_id' => $this->mine->id, 'name' => 'Priya']);
        $this->arun = User::factory()->create(['batch_id' => $other->id, 'name' => 'Arun']);

        $group = QuestionGroup::create(['title' => 'Basics']);
        $this->q = Question::create(['question_group_id' => $group->id, 'type' => 'multiple_choice', 'marks' => 2, 'text' => 'Pick B', 'data' => ['opts' => ['A', 'B'], 'correct' => [1]], 'explanation' => 'B is right']);
        $this->quiz = Assessment::create(['type' => 'quiz', 'title' => 'Basics quiz', 'course' => 'Communication', 'status' => 'published', 'min_percent' => 60, 'attempts' => 0, 'setup' => ['multi' => true]]);
        $this->quiz->groups()->sync([$group->id]);
    }

    private function attempt(User $student, array $over = []): QuizAttempt
    {
        return QuizAttempt::create($over + [
            'assessment_id' => $this->quiz->id, 'student_id' => $student->id, 'attempt_no' => 1, 'question_order' => [$this->q->id],
            'started_at' => now()->subMinutes(10), 'submitted_at' => now()->subMinutes(5), 'marks' => 2, 'total' => 2, 'result_percent' => 100,
            'passed' => true, 'needs_manual_grading' => false, 'answers' => [['question_id' => $this->q->id, 'answer' => [1], 'marks' => 2, 'correct' => true]],
        ]);
    }

    public function test_admin_sees_every_attempt_and_the_counts_per_outcome(): void
    {
        $this->attempt($this->priya);
        $this->attempt($this->arun, ['passed' => false, 'marks' => 0, 'result_percent' => 0]);
        $expired = now()->subHour();
        $this->attempt($this->arun, ['attempt_no' => 2, 'expires_at' => $expired, 'submitted_at' => $expired, 'passed' => false, 'marks' => 0, 'result_percent' => 0, 'answers' => []]);
        $this->attempt($this->priya, ['attempt_no' => 2, 'submitted_at' => null, 'passed' => null, 'answers' => null]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson(self::BASE)->assertOk()
            ->assertJsonCount(4, 'attempts')
            ->assertJsonPath('counts', ['total' => 4, 'passed' => 1, 'not_passed' => 1, 'needs_review' => 0, 'timed_out' => 1, 'in_progress' => 1]);

        $this->getJson(self::BASE.'?outcome=timed_out')->assertOk()->assertJsonCount(1, 'attempts')->assertJsonPath('attempts.0.outcome', 'timed_out');
        $this->getJson(self::BASE.'?q=pri')->assertOk()->assertJsonCount(2, 'attempts');
        $this->getJson(self::BASE.'?outcome=nonsense')->assertUnprocessable();
    }

    public function test_assessments_share_the_attempts_view_and_can_be_filtered_by_type(): void
    {
        $assessment = Assessment::create(['type' => 'assessment', 'title' => 'Skills assessment', 'course' => 'Communication', 'status' => 'published', 'min_percent' => 60, 'attempts' => 0, 'setup' => ['multi' => true]]);
        $this->attempt($this->priya);
        $this->attempt($this->priya, ['assessment_id' => $assessment->id]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(2, 'attempts');
        $this->getJson(self::BASE.'?type=assessment')->assertOk()->assertJsonCount(1, 'attempts')->assertJsonPath('attempts.0.assessment.type', 'assessment');
        $this->getJson(self::BASE.'?type=quiz')->assertOk()->assertJsonCount(1, 'attempts');
        $this->getJson(self::BASE.'?type=assignment')->assertUnprocessable();
    }

    public function test_a_mentor_only_sees_their_own_batch(): void
    {
        $mine = $this->attempt($this->priya);
        $theirs = $this->attempt($this->arun);

        Sanctum::actingAs($this->mentor);
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(1, 'attempts')->assertJsonPath('counts.total', 1);
        $this->getJson(self::BASE.'/'.$mine->id)->assertOk();
        $this->getJson(self::BASE.'/'.$theirs->id)->assertForbidden();
    }

    public function test_students_cannot_use_the_staff_endpoints(): void
    {
        $a = $this->attempt($this->priya);
        Sanctum::actingAs($this->priya);
        $this->getJson(self::BASE)->assertForbidden();
        $this->getJson(self::BASE.'/'.$a->id)->assertForbidden();
    }

    public function test_detail_lists_the_questions_served_with_answers_and_the_right_answer(): void
    {
        $a = $this->attempt($this->priya);
        Sanctum::actingAs($this->mentor);

        $this->getJson(self::BASE.'/'.$a->id)->assertOk()
            ->assertJsonPath('attempt.outcome', 'passed')
            ->assertJsonPath('attempt.student.name', 'Priya')
            ->assertJsonCount(1, 'questions')
            ->assertJsonPath('questions.0.your_answer', [1])
            ->assertJsonPath('questions.0.correct_answer', [1])
            ->assertJsonPath('questions.0.correct', true)
            ->assertJsonPath('questions.0.explanation', 'B is right');
    }

    /** A pending attempt: one multiple-choice question answered right plus one long answer nobody has marked. */
    private function pendingAttempt(User $student): QuizAttempt
    {
        $long = Question::create(['question_group_id' => $this->q->question_group_id, 'type' => 'long_answer', 'marks' => 5, 'text' => 'Write an email', 'data' => ['rubric' => 'Polite, clear']]);
        $this->long = $long;

        return $this->attempt($student, [
            'question_order' => [$this->q->id, $long->id], 'marks' => 2, 'total' => 7, 'result_percent' => 100, 'passed' => true, 'needs_manual_grading' => true,
            'answers' => [
                ['question_id' => $this->q->id, 'answer' => [1], 'marks' => 2, 'correct' => true],
                ['question_id' => $long->id, 'answer' => 'Hi, please move it', 'marks' => 0, 'correct' => null],
            ],
        ]);
    }

    private Question $long;

    public function test_a_mentor_marks_a_long_answer_and_the_attempt_is_rescored(): void
    {
        $a = $this->pendingAttempt($this->priya);
        Sanctum::actingAs($this->mentor);

        $this->postJson(self::BASE.'/'.$a->id.'/grade', ['question_id' => $this->long->id, 'marks' => 4, 'feedback' => 'Good'])->assertOk()
            ->assertJsonPath('attempt.marks', 6)
            ->assertJsonPath('attempt.result_percent', 86) // 6 of 7
            ->assertJsonPath('attempt.needs_manual_grading', false)
            ->assertJsonPath('attempt.outcome', 'passed')
            ->assertJsonPath('questions.1.marked', true)
            ->assertJsonPath('questions.1.feedback', 'Good')
            ->assertJsonPath('questions.1.marked_by', $this->mentor->name);

        $this->assertDatabaseHas('quiz_results', ['assessment_id' => $this->quiz->id, 'student_id' => $this->priya->id, 'marks' => 6, 'total' => 7]);

        // Re-marking is allowed and recalculates.
        $this->postJson(self::BASE.'/'.$a->id.'/grade', ['question_id' => $this->long->id, 'marks' => 0])->assertOk()
            ->assertJsonPath('attempt.marks', 2)->assertJsonPath('attempt.outcome', 'not_passed');
    }

    public function test_marking_is_limited_to_long_answers_within_range_and_within_scope(): void
    {
        $a = $this->pendingAttempt($this->priya);
        Sanctum::actingAs($this->mentor);

        $this->postJson(self::BASE.'/'.$a->id.'/grade', ['question_id' => $this->long->id, 'marks' => 6])->assertUnprocessable()->assertJsonValidationErrors(['marks']);
        $this->postJson(self::BASE.'/'.$a->id.'/grade', ['question_id' => $this->q->id, 'marks' => 1])->assertUnprocessable()->assertJsonValidationErrors(['question_id']);

        $theirs = $this->pendingAttempt($this->arun);
        $this->postJson(self::BASE.'/'.$theirs->id.'/grade', ['question_id' => $this->long->id, 'marks' => 3])->assertForbidden();

        Sanctum::actingAs($this->priya);
        $this->postJson(self::BASE.'/'.$a->id.'/grade', ['question_id' => $this->long->id, 'marks' => 3])->assertForbidden();
    }
}
