<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private function actAs(Role $role): User
    {
        $user = User::factory()->role($role)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function mcq(int $groupId, int $marks = 2): array
    {
        return ['group_id' => $groupId, 'type' => 'multiple_choice', 'marks' => $marks, 'text' => 'Pick one', 'data' => ['opts' => ['a', 'b'], 'correct' => [0]]];
    }

    public function test_quiz_total_follows_its_groups_and_questions(): void
    {
        $this->actAs(Role::Admin);

        $group = $this->postJson(self::BASE.'/question-groups', ['title' => 'Basics'])->assertCreated()->json('group.id');
        $this->postJson(self::BASE.'/questions', $this->mcq($group))->assertCreated();
        $quiz = $this->postJson(self::BASE.'/assessments', ['type' => 'quiz', 'title' => 'Quiz 1', 'course' => 'Communication', 'group_ids' => [$group]])
            ->assertCreated()->assertJsonPath('assessment.max_marks', 2)->json('assessment.id');

        $this->postJson(self::BASE.'/questions', $this->mcq($group, 3))->assertCreated();
        $this->getJson(self::BASE.'/assessments?type=quiz')->assertJsonPath('assessments.0.max_marks', 5);

        $this->putJson(self::BASE."/assessments/{$quiz}/setup", [
            'per_question' => 2, 'attempts' => 3, 'min_percent' => 80, 'review' => true, 'random' => true, 'multi' => true, 'same_page' => false,
            'explanations' => true, 'answer_sheets' => true, 'score_report' => true, 'total_correct' => true, 'answer_sheet' => 'wrong', 'group_ids' => [$group],
        ])->assertOk()->assertJsonPath('assessment.min_percent', 80)->assertJsonPath('assessment.pass_marks', 4);
    }

    public function test_assignment_needs_date_and_marks_but_quiz_does_not(): void
    {
        $this->actAs(Role::Admin);

        $this->postJson(self::BASE.'/assessments', ['type' => 'assignment', 'title' => 'Essay', 'course' => 'C'])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'max_marks']);
        $this->postJson(self::BASE.'/assessments', ['type' => 'assignment', 'title' => 'Essay', 'course' => 'C', 'due_date' => '2026-12-01', 'max_marks' => 25, 'min_percent' => 60])
            ->assertCreated()->assertJsonPath('assessment.pass_marks', 15);
    }

    public function test_renaming_a_course_repoints_its_assessments(): void
    {
        $this->actAs(Role::Admin);

        $this->postJson(self::BASE.'/assessments', ['type' => 'quiz', 'title' => 'Quiz 1', 'course' => 'Old'])->assertCreated();
        $this->putJson(self::BASE.'/assessments/rename-course', ['from' => 'Old', 'to' => 'New'])->assertOk()->assertJsonPath('updated', 1);
        $this->getJson(self::BASE.'/assessments?course=New')->assertJsonCount(1, 'assessments');
    }

    public function test_group_with_questions_cannot_be_deleted(): void
    {
        $this->actAs(Role::Admin);

        $group = $this->postJson(self::BASE.'/question-groups', ['title' => 'Basics'])->json('group.id');
        $this->postJson(self::BASE.'/questions', $this->mcq($group))->assertCreated();

        $this->deleteJson(self::BASE."/question-groups/{$group}")->assertUnprocessable();
    }

    public function test_quiz_report_derives_percent_and_pass(): void
    {
        $this->actAs(Role::Admin);
        $student = User::factory()->create();

        $group = $this->postJson(self::BASE.'/question-groups', ['title' => 'Basics'])->json('group.id');
        $this->postJson(self::BASE.'/questions', $this->mcq($group, 10))->assertCreated();
        $quiz = $this->postJson(self::BASE.'/assessments', ['type' => 'quiz', 'title' => 'Quiz 1', 'course' => 'C', 'group_ids' => [$group]])->json('assessment.id');

        $this->postJson(self::BASE.'/quiz-results', ['assessment_id' => $quiz, 'student_id' => $student->id, 'marks' => 4])
            ->assertCreated()->assertJsonPath('result.result_percent', 40)->assertJsonPath('result.passed', false);
        $this->getJson(self::BASE.'/quiz-results?q='.$student->name)->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_students_cannot_read_the_bank_or_write(): void
    {
        $this->actAs(Role::Student);

        $this->getJson(self::BASE.'/questions')->assertForbidden();
        $this->getJson(self::BASE.'/quiz-results')->assertForbidden();
        $this->postJson(self::BASE.'/assessments', ['type' => 'quiz', 'title' => 'X', 'course' => 'C'])->assertForbidden();
        $this->getJson(self::BASE.'/assessments')->assertOk();
    }
}
