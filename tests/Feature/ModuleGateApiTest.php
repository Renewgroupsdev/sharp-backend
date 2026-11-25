<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use App\Models\Notification;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\QuizResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleGateApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private Course $course;

    private Batch $batch;

    private User $student;

    private User $mentor;

    /** @var array<string, CourseLesson> */
    private array $lesson = [];

    private Assessment $quiz1;

    private Assessment $quiz2;

    private array $chapter = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create(['name' => 'Batch 1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        $this->course->batches()->sync([$this->batch->id]);

        foreach (['Module 1' => ['a1', 'a2'], 'Module 2' => ['b1'], 'Module 3' => ['c1']] as $name => $lessons) {
            $chapter = $this->course->chapters()->create(['name' => $name, 'status' => 'Published', 'position' => count($this->chapter)]);
            $this->chapter[$name] = $chapter;
            foreach ($lessons as $l) {
                $this->lesson[$l] = $chapter->lessons()->create(['name' => $l, 'duration_minutes' => 5, 'host' => 'Editor', 'content' => 'x', 'privacy' => 'Enrolled students only']);
            }
        }

        $group = QuestionGroup::create(['title' => 'G']);
        Question::create(['question_group_id' => $group->id, 'type' => 'short_answer', 'marks' => 1, 'text' => 'Q', 'data' => ['shortAns' => 'x']]);
        $quiz = fn (string $module) => tap(Assessment::create([
            'type' => 'quiz', 'title' => "{$module} quiz", 'course' => 'Communication', 'module' => $module, 'status' => 'published', 'min_percent' => 60, 'attempts' => 3,
            'setup' => ['per_question' => 0, 'review' => true, 'random' => false, 'multi' => true, 'same_page' => false, 'explanations' => true, 'answer_sheets' => true, 'score_report' => true, 'total_correct' => true, 'answer_sheet' => 'correct'],
        ]), fn ($a) => $a->groups()->sync([$group->id]));
        $this->quiz1 = $quiz('Module 1');
        $this->quiz2 = $quiz('Module 2');

        $this->student = User::factory()->create(['batch_id' => $this->batch->id]);
        $this->mentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]);
        Sanctum::actingAs($this->student);
    }

    private function complete(string $key)
    {
        return $this->postJson(self::BASE."/my/lessons/{$this->lesson[$key]->id}/complete");
    }

    private function pass(Assessment $quiz, int $percent = 80): void
    {
        QuizResult::create(['assessment_id' => $quiz->id, 'student_id' => $this->student->id, 'marks' => $percent, 'total' => 100, 'result_percent' => $percent, 'attempts' => 1]);
    }

    private function modules(): array
    {
        return collect($this->getJson(self::BASE."/my/courses/{$this->course->id}")->assertOk()->json('modules'))->keyBy('name')->all();
    }

    public function test_only_the_first_module_is_open_at_the_start(): void
    {
        $m = $this->modules();

        $this->assertFalse($m['Module 1']['locked']);
        $this->assertTrue($m['Module 2']['locked']);
        $this->assertTrue($m['Module 3']['locked']);
        $this->assertStringContainsString('Module 1', $m['Module 2']['reason']);
        $this->assertStringContainsString('complete all its lessons', $m['Module 2']['reason']);
        $this->assertStringContainsString('pass its quiz', $m['Module 2']['reason']);
        $this->assertSame(1, count($m['Module 1']['quizzes']));

        $this->postJson(self::BASE."/my/lessons/{$this->lesson['b1']->id}/open")->assertForbidden();
        $this->complete('b1')->assertForbidden();
        $this->assertDatabaseCount('lesson_progress', 0);
    }

    public function test_a_module_opens_only_when_the_previous_one_is_finished_and_its_quiz_passed(): void
    {
        $this->complete('a1')->assertOk();
        $this->complete('a2')->assertOk();
        $this->assertTrue($this->modules()['Module 2']['locked']); // lessons alone are not enough
        $this->assertStringContainsString('pass its quiz', $this->modules()['Module 2']['reason']);

        $this->pass($this->quiz1, 40); // below the 60% pass mark
        $this->assertTrue($this->modules()['Module 2']['locked']);

        QuizResult::where('assessment_id', $this->quiz1->id)->update(['result_percent' => 75]);
        $m = $this->modules();
        $this->assertFalse($m['Module 2']['locked']);
        $this->assertTrue($m['Module 1']['complete']);
        $this->assertTrue($m['Module 3']['locked']); // one step at a time

        $this->complete('b1')->assertOk();
        $this->assertTrue($this->modules()['Module 3']['locked']); // Module 2's quiz is not passed yet
        $this->pass($this->quiz2);
        $this->assertFalse($this->modules()['Module 3']['locked']);
        $this->complete('c1')->assertOk()->assertJsonPath('progress.progress', 100);
    }

    public function test_a_quiz_of_a_locked_module_cannot_be_started_and_the_list_says_why(): void
    {
        $list = collect($this->getJson(self::BASE.'/my/assessments')->assertOk()->json('assessments'))->keyBy('title');
        $this->assertNull($list['Module 1 quiz']['locked_reason']);
        $this->assertStringContainsString('Module 1', $list['Module 2 quiz']['locked_reason']);

        $this->postJson(self::BASE."/my/assessments/{$this->quiz2->id}/start")->assertForbidden();
        $this->postJson(self::BASE."/my/assessments/{$this->quiz1->id}/start")->assertOk();
    }

    public function test_a_mentor_can_open_a_module_early_with_a_reason(): void
    {
        $chapter = $this->chapter['Module 2'];
        Sanctum::actingAs($this->mentor);

        $this->postJson(self::BASE.'/module-unlocks', ['student_id' => $this->student->public_id, 'chapter_id' => $chapter->id, 'reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson(self::BASE.'/module-unlocks', ['student_id' => $this->student->public_id, 'chapter_id' => $chapter->id, 'reason' => 'Joined the batch late'])
            ->assertCreated()->assertJsonPath('unlock.module', 'Module 2')->assertJsonPath('unlock.unlocked_by', $this->mentor->name);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->student->id, 'type' => 'learning', 'title' => 'A module was unlocked for you']);
        $this->postJson(self::BASE.'/module-unlocks', ['student_id' => $this->student->public_id, 'chapter_id' => $chapter->id, 'reason' => 'Again please'])
            ->assertUnprocessable()->assertJsonValidationErrors(['chapter_id']); // already open
        $this->postJson(self::BASE.'/module-unlocks', ['student_id' => $this->student->public_id, 'chapter_id' => $this->chapter['Module 1']->id, 'reason' => 'It is open anyway'])
            ->assertUnprocessable();

        Sanctum::actingAs($this->student);
        $m = $this->modules();
        $this->assertFalse($m['Module 2']['locked']);
        $this->assertTrue($m['Module 2']['unlocked_early']);
        $this->assertTrue($m['Module 3']['locked']);
        $this->complete('b1')->assertOk();
        $this->postJson(self::BASE."/my/assessments/{$this->quiz2->id}/start")->assertOk(); // its quiz opened with it
    }

    public function test_the_unlock_list_the_overview_and_revoking(): void
    {
        $chapter = $this->chapter['Module 2'];
        Sanctum::actingAs($this->mentor);
        $this->postJson(self::BASE.'/module-unlocks', ['student_id' => $this->student->public_id, 'chapter_id' => $chapter->id, 'reason' => 'Joined the batch late'])->assertCreated();

        $this->getJson(self::BASE.'/module-unlocks')->assertOk()->assertJsonCount(1, 'unlocks')
            ->assertJsonPath('unlocks.0.reason', 'Joined the batch late')->assertJsonPath('unlocks.0.course', 'Communication');
        $this->getJson(self::BASE.'/module-unlocks?student_id='.urlencode($this->student->public_id))->assertJsonCount(1, 'unlocks');

        $overview = $this->getJson(self::BASE.'/module-unlocks/overview?student_id='.urlencode($this->student->public_id))->assertOk()
            ->assertJsonCount(1, 'courses')->assertJsonCount(3, 'courses.0.modules');
        $this->assertTrue($overview->json('courses.0.modules.2.locked'));
        $this->assertFalse($overview->json('courses.0.modules.1.locked'));

        $id = $this->getJson(self::BASE.'/module-unlocks')->json('unlocks.0.id');
        $this->deleteJson(self::BASE."/module-unlocks/{$id}")->assertOk();
        $this->assertDatabaseCount('module_unlocks', 0);

        Sanctum::actingAs($this->student);
        $this->assertTrue($this->modules()['Module 2']['locked']);
    }

    public function test_mentors_of_other_batches_students_and_non_staff_are_refused(): void
    {
        $other = User::factory()->role(Role::Mentor)->create(['batch_id' => Batch::create(['name' => 'Batch 2', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 5])->id]);
        $payload = ['student_id' => $this->student->public_id, 'chapter_id' => $this->chapter['Module 2']->id, 'reason' => 'A genuine reason'];

        Sanctum::actingAs($other);
        $this->postJson(self::BASE.'/module-unlocks', $payload)->assertForbidden();
        $this->getJson(self::BASE.'/module-unlocks/overview?student_id='.urlencode($this->student->public_id))->assertForbidden();
        $this->getJson(self::BASE.'/module-unlocks')->assertOk()->assertJsonCount(0, 'unlocks');

        Sanctum::actingAs($this->student);
        $this->postJson(self::BASE.'/module-unlocks', $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->postJson(self::BASE.'/module-unlocks', $payload)->assertCreated(); // staff may unlock any student
    }

    public function test_draft_modules_are_skipped_in_the_sequence(): void
    {
        $this->chapter['Module 2']->update(['status' => 'Draft']);

        $m = $this->modules();
        $this->assertSame(['Module 1', 'Module 3'], array_keys($m));
        $this->assertTrue($m['Module 3']['locked']);
        $this->assertStringContainsString('Module 1', $m['Module 3']['reason']);
        $this->assertSame(0, Notification::count());
    }

    public function test_a_lesson_opens_only_after_the_one_before_it_is_completed(): void
    {
        $lessons = collect($this->modules()['Module 1']['lessons'])->keyBy('id');
        $this->assertFalse($lessons[$this->lesson['a1']->id]['locked']);
        $this->assertTrue($lessons[$this->lesson['a2']->id]['locked']);
        $this->assertStringContainsString('a1', $lessons[$this->lesson['a2']->id]['reason']);

        // The server refuses it, not just the screen.
        $this->postJson(self::BASE."/my/lessons/{$this->lesson['a2']->id}/open")->assertForbidden();
        $this->complete('a2')->assertForbidden();

        $this->complete('a1')->assertOk();
        $this->assertFalse(collect($this->modules()['Module 1']['lessons'])->firstWhere('id', $this->lesson['a2']->id)['locked']);
        $this->postJson(self::BASE."/my/lessons/{$this->lesson['a2']->id}/open")->assertOk();
    }
}
