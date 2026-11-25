<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyAssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web/my/assessments';

    private Course $course;

    private User $student;

    private Assessment $quiz;

    private QuestionGroup $group;

    /** @var array<string, Question> */
    private array $q = [];

    protected function setUp(): void
    {
        parent::setUp();

        $batch = Batch::create(['name' => 'Batch 1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        $this->course->batches()->sync([$batch->id]);
        $this->student = User::factory()->create(['batch_id' => $batch->id]);

        $this->group = QuestionGroup::create(['title' => 'Basics']);
        $make = fn (string $type, int $marks, array $data, ?string $explanation = null) => Question::create([
            'question_group_id' => $this->group->id, 'type' => $type, 'marks' => $marks, 'text' => "Q {$type}", 'data' => $data, 'explanation' => $explanation,
        ]);
        $this->q = [
            'mc' => $make('multiple_choice', 2, ['opts' => ['A', 'B', 'C'], 'correct' => [1]], 'B is right'),
            'multi' => $make('multiple_choice', 2, ['opts' => ['A', 'B', 'C'], 'correct' => [0, 2]]),
            'sort' => $make('sorting', 2, ['items' => ['First', 'Second', 'Third']]),
            'match' => $make('matching', 4, ['pairs' => [['a', '1'], ['b', '2']]]),
            'puzzle' => $make('puzzle', 1, ['puzzle' => 'Hello World', 'hint' => 'greeting']),
            'short' => $make('short_answer', 1, ['shortAns' => 'Paris', 'keywords' => 'eiffel,seine']),
        ];

        $this->quiz = $this->makeQuiz();
        Sanctum::actingAs($this->student);
    }

    private function makeQuiz(array $overrides = [], array $setup = []): Assessment
    {
        $quiz = Assessment::create($overrides + [
            'type' => 'quiz', 'title' => 'Basics quiz', 'course' => 'Communication', 'module' => 'M1', 'topic' => 'All topics',
            'status' => 'published', 'min_percent' => 60, 'attempts' => 2,
            'setup' => $setup + ['per_question' => 0, 'review' => true, 'random' => false, 'multi' => true, 'same_page' => false, 'explanations' => true,
                'answer_sheets' => true, 'score_report' => true, 'total_correct' => true, 'answer_sheet' => 'correct'],
        ]);
        $quiz->groups()->sync([$this->group->id]);
        $quiz->update(['max_marks' => $quiz->quizTotal()]);

        return $quiz;
    }

    private function perfect(): array
    {
        return [
            ['question_id' => $this->q['mc']->id, 'answer' => [1]],
            ['question_id' => $this->q['multi']->id, 'answer' => [2, 0]],
            ['question_id' => $this->q['sort']->id, 'answer' => ['First', 'Second', 'Third']],
            ['question_id' => $this->q['match']->id, 'answer' => [['a', '1'], ['b', '2']]],
            ['question_id' => $this->q['puzzle']->id, 'answer' => '  hello   WORLD '],
            ['question_id' => $this->q['short']->id, 'answer' => 'Climb the Eiffel tower'],
        ];
    }

    private function start(?Assessment $quiz = null): array
    {
        return $this->postJson(self::BASE.'/'.($quiz ?? $this->quiz)->id.'/start')->assertOk()->json();
    }

    private function submit(int $attemptId, array $answers, ?Assessment $quiz = null)
    {
        return $this->postJson(self::BASE.'/'.($quiz ?? $this->quiz)->id.'/submit', ['attempt_id' => $attemptId, 'answers' => $answers]);
    }

    public function test_lists_only_published_assessments_of_the_students_courses(): void
    {
        $this->makeQuiz(['title' => 'Draft quiz', 'status' => 'draft']);
        $this->makeQuiz(['title' => 'Other course', 'course' => 'Something else']);
        Assessment::create(['type' => 'assignment', 'title' => 'Essay', 'course' => 'Communication', 'status' => 'published', 'due_date' => '2026-12-01', 'max_marks' => 50, 'min_percent' => 60]);

        $list = $this->getJson(self::BASE)->assertOk()->assertJsonCount(2, 'assessments')->json('assessments');
        $quiz = collect($list)->firstWhere('title', 'Basics quiz');
        $this->assertSame(6, $quiz['questions_count']);
        $this->assertSame(0, $quiz['attempts_used']);
        $this->assertNull($quiz['attempts_allowed']); // multiple attempts on = no cap
        $this->assertNull($quiz['best_percent']);
        $essay = collect($list)->firstWhere('title', 'Essay');
        $this->assertNull($essay['attempts_used']);
        $this->assertSame('2026-12-01', $essay['due_date']);
    }

    public function test_starting_serves_the_questions_without_any_answer(): void
    {
        $res = $this->start();
        $this->assertCount(6, $res['questions']);

        $json = json_encode($res);
        foreach (['"correct"', '"data"', 'shortAns', 'keywords', 'B is right', '"explanation"'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} leaked to the learner");
        }

        $by = collect($res['questions'])->keyBy('id');
        $this->assertFalse($by[$this->q['mc']->id]['multiple']);
        $this->assertTrue($by[$this->q['multi']->id]['multiple']);
        $this->assertEqualsCanonicalizing(['First', 'Second', 'Third'], $by[$this->q['sort']->id]['items']);
        $this->assertNotSame(['First', 'Second', 'Third'], $by[$this->q['sort']->id]['items']);
        $this->assertSame(['a', 'b'], $by[$this->q['match']->id]['left']);
        $this->assertEqualsCanonicalizing(['1', '2'], $by[$this->q['match']->id]['right']);
        $this->assertSame('greeting', $by[$this->q['puzzle']->id]['hint']);
        $this->assertSame(strlen('Hello World'), strlen($by[$this->q['puzzle']->id]['scrambled']));
    }

    public function test_a_perfect_attempt_passes_and_is_marked_on_the_server(): void
    {
        $attempt = $this->start()['attempt'];

        $this->submit($attempt['id'], $this->perfect())->assertOk()
            ->assertJsonPath('result.marks', 12)->assertJsonPath('result.total', 12)->assertJsonPath('result.result_percent', 100)
            ->assertJsonPath('result.passed', true)->assertJsonPath('result.correct_count', 6)->assertJsonPath('result.attempts_left', null);

        $this->assertDatabaseHas('quiz_results', ['assessment_id' => $this->quiz->id, 'student_id' => $this->student->id, 'result_percent' => 100, 'attempts' => 1]);
        $this->submit($attempt['id'], $this->perfect())->assertUnprocessable()->assertJsonValidationErrors(['attempt']);
    }

    public function test_marking_each_question_type_with_partial_credit_for_matching(): void
    {
        $attempt = $this->start()['attempt'];
        $answers = [
            ['question_id' => $this->q['mc']->id, 'answer' => [0]],                                  // wrong
            ['question_id' => $this->q['multi']->id, 'answer' => [0, 2]],                            // right
            ['question_id' => $this->q['sort']->id, 'answer' => ['Second', 'First', 'Third']],       // wrong order
            ['question_id' => $this->q['match']->id, 'answer' => [['a', '1'], ['b', '1']]],          // half
            ['question_id' => $this->q['puzzle']->id, 'answer' => 'hello world'],                    // right
            ['question_id' => $this->q['short']->id, 'answer' => 'London'],                          // wrong
        ];

        $this->submit($attempt['id'], $answers)->assertOk()
            ->assertJsonPath('result.marks', 5)->assertJsonPath('result.result_percent', 42)->assertJsonPath('result.passed', false)
            ->assertJsonPath('result.correct_count', 2);
    }

    public function test_unanswered_questions_score_zero_and_an_answer_map_also_works_for_matching(): void
    {
        $attempt = $this->start()['attempt'];

        $this->submit($attempt['id'], [['question_id' => $this->q['match']->id, 'answer' => ['a' => '1', 'b' => '2']]])->assertOk()
            ->assertJsonPath('result.marks', 4)->assertJsonPath('result.result_percent', 33);
    }

    public function test_unlimited_attempts_resume_and_best_result_is_kept(): void
    {
        $first = $this->start()['attempt'];
        $again = $this->start()['attempt'];
        $this->assertSame($first['id'], $again['id']); // a refresh resumes, it does not burn an attempt
        $this->assertSame(1, QuizAttempt::count());

        $this->submit($first['id'], $this->perfect())->assertOk();

        $second = $this->start()['attempt'];
        $this->assertSame(2, $second['attempt_no']);
        $this->submit($second['id'], [])->assertOk()->assertJsonPath('result.result_percent', 0)->assertJsonPath('result.attempts_left', null);

        $this->assertDatabaseHas('quiz_results', ['assessment_id' => $this->quiz->id, 'result_percent' => 100, 'attempts' => 2]); // best kept
        $third = $this->start()['attempt']; // no cap: a third attempt is allowed
        $this->assertSame(3, $third['attempt_no']);
        $this->submit($third['id'], [])->assertOk();

        $this->getJson(self::BASE.'/'.$this->quiz->id)->assertOk()
            ->assertJsonCount(3, 'attempts')->assertJsonPath('assessment.best_percent', 100)->assertJsonPath('assessment.attempts_used', 3)
            ->assertJsonPath('in_progress', null);
    }

    public function test_with_multiple_attempts_off_the_quiz_allows_exactly_one(): void
    {
        $single = $this->makeQuiz(['title' => 'Single'], ['multi' => false]);
        $first = $this->start($single)['attempt'];
        $this->submit($first['id'], $this->perfect(), $single)->assertOk()->assertJsonPath('result.attempts_left', 0);
        $this->postJson(self::BASE.'/'.$single->id.'/start')->assertUnprocessable()->assertJsonValidationErrors(['attempt']);
    }

    public function test_a_timed_quiz_has_a_deadline_and_a_late_attempt_is_closed_without_marks(): void
    {
        $timed = $this->makeQuiz(['title' => 'Timed', 'attempts' => 1], ['per_question' => 1]);

        $res = $this->start($timed);
        $this->assertNotNull($res['attempt']['expires_at']);
        $this->getJson(self::BASE)->assertJsonPath('assessments.1.time_limit_sec', 6 * 60);

        QuizAttempt::find($res['attempt']['id'])->update(['expires_at' => now()->subMinutes(5)]);
        $this->submit($res['attempt']['id'], $this->perfect(), $timed)->assertUnprocessable();

        $this->assertDatabaseHas('quiz_attempts', ['id' => $res['attempt']['id'], 'passed' => false, 'result_percent' => 0]);
        $this->postJson(self::BASE.'/'.$timed->id.'/start')->assertUnprocessable(); // the expired attempt used the only one
    }

    public function test_long_answers_wait_for_a_mentor_and_do_not_count_against_the_student(): void
    {
        $long = Question::create(['question_group_id' => $this->group->id, 'type' => 'long_answer', 'marks' => 8, 'text' => 'Explain', 'data' => ['rubric' => 'model', 'wordlimit' => 100]]);
        $this->quiz->update(['max_marks' => $this->quiz->quizTotal()]);

        $res = $this->start();
        $this->assertSame(100, collect($res['questions'])->firstWhere('id', $long->id)['word_limit']);
        $this->assertStringNotContainsString('model', json_encode($res));

        $answers = [...$this->perfect(), ['question_id' => $long->id, 'answer' => 'My long essay']];
        $this->submit($res['attempt']['id'], $answers)->assertOk()
            ->assertJsonPath('result.marks', 12)->assertJsonPath('result.total', 20)->assertJsonPath('result.result_percent', 100)
            ->assertJsonPath('result.needs_manual_grading', true);
    }

    public function test_review_follows_the_quiz_setup(): void
    {
        $attempt = $this->start()['attempt'];
        $wrong = $this->perfect();
        $wrong[0]['answer'] = [0]; // MC wrong
        $this->submit($attempt['id'], $wrong)->assertOk();

        $review = $this->getJson(self::BASE.'/'.$this->quiz->id.'/review')->assertOk()->assertJsonCount(6, 'questions');
        $mc = collect($review->json('questions'))->firstWhere('id', $this->q['mc']->id);
        $this->assertSame([0], $mc['your_answer']);
        $this->assertFalse($mc['correct']);
        $this->assertSame([1], $mc['correct_answer']);
        $this->assertSame('B is right', $mc['explanation']);
        $this->assertSame(['A', 'B', 'C'], $mc['options']);

        // "wrong" mode: the sheet only shows the answers the student got wrong.
        $wrongOnly = $this->makeQuiz(['title' => 'Wrong only'], ['answer_sheet' => 'wrong']);
        $a2 = $this->start($wrongOnly)['attempt'];
        $this->submit($a2['id'], $wrong, $wrongOnly)->assertOk();
        $rows = collect($this->getJson(self::BASE.'/'.$wrongOnly->id.'/review')->json('questions'));
        $this->assertArrayHasKey('correct_answer', $rows->firstWhere('id', $this->q['mc']->id));
        $this->assertArrayNotHasKey('correct_answer', $rows->firstWhere('id', $this->q['multi']->id));

        // "none" mode and review switched off.
        $none = $this->makeQuiz(['title' => 'No sheet'], ['answer_sheet' => 'none']);
        $a3 = $this->start($none)['attempt'];
        $this->submit($a3['id'], $wrong, $none)->assertOk();
        $this->assertArrayNotHasKey('correct_answer', $this->getJson(self::BASE.'/'.$none->id.'/review')->json('questions.0'));

        $off = $this->makeQuiz(['title' => 'No review'], ['review' => false]);
        $a4 = $this->start($off)['attempt'];
        $this->submit($a4['id'], [], $off)->assertOk()->assertJsonPath('result.review_allowed', false);
        $this->getJson(self::BASE.'/'.$off->id.'/review')->assertForbidden();
    }

    public function test_the_score_can_be_hidden_by_the_quiz_setup(): void
    {
        $quiet = $this->makeQuiz(['title' => 'Quiet'], ['score_report' => false]);
        $attempt = $this->start($quiet)['attempt'];

        $res = $this->submit($attempt['id'], $this->perfect(), $quiet)->assertOk()->assertJsonPath('result.show_score', false);
        $this->assertArrayNotHasKey('marks', $res->json('result'));
        $this->assertArrayNotHasKey('result_percent', $res->json('result'));
    }

    public function test_random_order_is_fixed_at_start_and_kept_on_resume(): void
    {
        $random = $this->makeQuiz(['title' => 'Random'], ['random' => true]);

        $a = $this->start($random);
        $b = $this->start($random);
        $this->assertSame(array_column($a['questions'], 'id'), array_column($b['questions'], 'id'));
        $this->assertEqualsCanonicalizing(QuizAttempt::find($a['attempt']['id'])->question_order, array_column($a['questions'], 'id'));
    }

    public function test_random_quiz_gives_each_student_their_own_limited_list(): void
    {
        $random = $this->makeQuiz(['title' => 'Random'], ['random' => true, 'question_count' => 3]);

        $mine = array_column($this->start($random)['questions'], 'id');
        $this->assertCount(3, $mine);
        $this->assertCount(3, array_unique($mine));

        $this->getJson(self::BASE)->assertJsonPath('assessments.1.questions_count', 3);
    }

    public function test_only_enrolled_students_can_attempt_and_staff_are_blocked(): void
    {
        $outsider = User::factory()->create(['batch_id' => Batch::create(['name' => 'Other', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 5])->id]);
        Sanctum::actingAs($outsider);
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'assessments');
        $this->postJson(self::BASE.'/'.$this->quiz->id.'/start')->assertNotFound();

        $assignment = Assessment::create(['type' => 'assignment', 'title' => 'Essay', 'course' => 'Communication', 'status' => 'published', 'max_marks' => 10]);
        Sanctum::actingAs($this->student);
        $this->postJson(self::BASE.'/'.$assignment->id.'/start')->assertUnprocessable();

        $empty = $this->makeQuiz(['title' => 'Empty']);
        $empty->groups()->sync([]);
        $this->postJson(self::BASE.'/'.$empty->id.'/start')->assertUnprocessable();

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->getJson(self::BASE)->assertForbidden();
    }
}
