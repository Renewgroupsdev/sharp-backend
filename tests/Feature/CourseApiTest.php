<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private function actAs(Role $role): User
    {
        $user = User::factory()->role($role)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function category(string $name = 'Soft skills'): int
    {
        return CourseCategory::create(['name' => $name])->id;
    }

    private function payload(int $categoryId, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Communication',
            'course_category_id' => $categoryId,
            'hours' => 6,
            'mentors' => ['Ms. Anitha Rajan'],
            'status' => 'Published',
        ], $overrides);
    }

    public function test_course_crud_with_curriculum(): void
    {
        $this->actAs(Role::Admin);
        $cat = $this->category();

        $id = $this->postJson(self::BASE.'/courses', $this->payload($cat, ['chapters' => [
            ['name' => 'Basics', 'lessons' => [
                ['name' => 'Intro', 'duration_minutes' => 10, 'host' => 'YouTube', 'url' => 'https://youtu.be/abc', 'privacy' => 'Public'],
                ['name' => 'Notes', 'duration_minutes' => 5, 'host' => 'Editor', 'content' => '<p>Hi</p>', 'privacy' => 'Private'],
            ]],
        ]]))->assertCreated()
            ->assertJsonPath('course.code', 'CRS-001')
            ->assertJsonPath('course.category', 'Soft skills')
            ->assertJsonPath('course.chapters.0.lessons.1.name', 'Notes')
            ->json('course.id');

        $this->putJson(self::BASE."/courses/{$id}", $this->payload($cat, ['title' => 'Communication 2', 'hours' => 8]))
            ->assertOk()->assertJsonPath('course.title', 'Communication 2')->assertJsonPath('course.chapters.0.name', 'Basics');

        $this->getJson(self::BASE.'/courses?q=communication')->assertOk()->assertJsonCount(1, 'courses');
        $this->getJson(self::BASE."/courses/{$id}")->assertOk()->assertJsonPath('course.hours', 8);
    }

    public function test_replacing_chapters_updates_in_place_and_removes_the_rest(): void
    {
        $this->actAs(Role::Admin);
        $course = $this->postJson(self::BASE.'/courses', $this->payload($this->category(), ['chapters' => [
            ['name' => 'One', 'lessons' => [['name' => 'A', 'duration_minutes' => 5, 'host' => 'Editor', 'content' => 'x', 'privacy' => 'Public']]],
            ['name' => 'Two'],
        ]]))->assertCreated()->json('course');

        $keep = $course['chapters'][0];
        $this->putJson(self::BASE."/courses/{$course['id']}/chapters", ['chapters' => [
            ['id' => $keep['id'], 'name' => 'One renamed', 'lessons' => []],
        ]])->assertOk()
            ->assertJsonCount(1, 'course.chapters')
            ->assertJsonPath('course.chapters.0.id', $keep['id'])
            ->assertJsonPath('course.chapters.0.name', 'One renamed')
            ->assertJsonCount(0, 'course.chapters.0.lessons');
    }

    public function test_lesson_rules_follow_the_host(): void
    {
        $this->actAs(Role::Admin);
        $cat = $this->category();

        $this->postJson(self::BASE.'/courses', $this->payload($cat, ['chapters' => [
            ['name' => 'C', 'lessons' => [['name' => 'Video', 'duration_minutes' => 5, 'host' => 'YouTube', 'privacy' => 'Public']]],
        ]]))->assertUnprocessable()->assertJsonValidationErrors(['chapters.0.lessons.0.url']);

        $this->postJson(self::BASE.'/courses', $this->payload($cat, ['chapters' => [
            ['name' => 'C', 'lessons' => [['name' => 'Text', 'duration_minutes' => 5, 'host' => 'Editor', 'privacy' => 'Public']]],
        ]]))->assertUnprocessable()->assertJsonValidationErrors(['chapters.0.lessons.0.content']);
    }

    public function test_title_must_be_unique_among_live_courses(): void
    {
        $this->actAs(Role::Admin);
        $cat = $this->category();

        $id = $this->postJson(self::BASE.'/courses', $this->payload($cat))->assertCreated()->json('course.id');
        $this->postJson(self::BASE.'/courses', $this->payload($cat))->assertUnprocessable()->assertJsonValidationErrors(['title']);

        // A trashed course frees its title, but it cannot be restored while a live one holds it.
        $this->deleteJson(self::BASE."/courses/{$id}")->assertOk();
        $this->postJson(self::BASE.'/courses', $this->payload($cat))->assertCreated();
        $this->postJson(self::BASE."/courses/{$id}/restore")->assertUnprocessable()->assertJsonValidationErrors(['title']);
    }

    public function test_trash_restore_and_purge(): void
    {
        $this->actAs(Role::Admin);
        $id = $this->postJson(self::BASE.'/courses', $this->payload($this->category()))->assertCreated()->json('course.id');

        $this->deleteJson(self::BASE."/courses/{$id}/purge")->assertUnprocessable(); // must be trashed first

        $this->deleteJson(self::BASE."/courses/{$id}")->assertOk();
        $this->getJson(self::BASE.'/courses')->assertJsonCount(0, 'courses');
        $this->getJson(self::BASE.'/courses?trashed=1')->assertJsonCount(1, 'courses');

        $this->postJson(self::BASE."/courses/{$id}/restore")->assertOk()->assertJsonPath('course.deleted_at', null);
        $this->deleteJson(self::BASE."/courses/{$id}")->assertOk();
        $this->deleteJson(self::BASE."/courses/{$id}/purge")->assertOk();
        $this->getJson(self::BASE.'/courses?trashed=1')->assertJsonCount(0, 'courses');
        $this->assertDatabaseCount('course_chapters', 0);
    }

    public function test_learners_only_see_published_courses_and_cannot_write(): void
    {
        $this->actAs(Role::Admin);
        $cat = $this->category();
        $draft = $this->postJson(self::BASE.'/courses', $this->payload($cat, ['title' => 'Hidden', 'status' => 'Draft']))->json('course.id');
        $this->postJson(self::BASE.'/courses', $this->payload($cat, ['title' => 'Visible']))->assertCreated();

        $this->actAs(Role::Student);
        $this->getJson(self::BASE.'/courses')->assertOk()->assertJsonCount(1, 'courses')->assertJsonPath('courses.0.title', 'Visible');
        $this->getJson(self::BASE."/courses/{$draft}")->assertNotFound();
        $this->postJson(self::BASE.'/courses', $this->payload($cat, ['title' => 'Nope']))->assertForbidden();
        $this->deleteJson(self::BASE."/courses/{$draft}")->assertForbidden();
    }

    public function test_category_crud_and_in_use_guard(): void
    {
        $this->actAs(Role::Admin);

        $cat = $this->postJson(self::BASE.'/course-categories', ['name' => 'Technical', 'description' => 'Tools'])
            ->assertCreated()->assertJsonPath('category.status', 'Active')->json('category.id');
        $this->postJson(self::BASE.'/course-categories', ['name' => 'Technical'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->putJson(self::BASE."/course-categories/{$cat}", ['name' => 'Tech', 'status' => 'Inactive'])
            ->assertOk()->assertJsonPath('category.name', 'Tech')->assertJsonPath('category.status', 'Inactive');

        $course = $this->postJson(self::BASE.'/courses', $this->payload($cat))->assertCreated()->json('course.id');
        $this->getJson(self::BASE.'/course-categories')->assertJsonPath('categories.0.course_count', 1);
        $this->deleteJson(self::BASE."/course-categories/{$cat}")->assertUnprocessable()->assertJsonValidationErrors(['category']);

        $this->deleteJson(self::BASE."/courses/{$course}")->assertOk(); // trashed courses still hold the category
        $this->deleteJson(self::BASE."/course-categories/{$cat}")->assertUnprocessable();
        $this->deleteJson(self::BASE."/courses/{$course}/purge")->assertOk();
        $this->deleteJson(self::BASE."/course-categories/{$cat}")->assertOk();
    }

    public function test_modules_keep_description_and_status_and_drafts_are_staff_only(): void
    {
        $this->actAs(Role::Admin);
        $course = $this->postJson(self::BASE.'/courses', $this->payload($this->category(), ['chapters' => [
            ['name' => 'Live module', 'description' => 'Start here', 'status' => 'Published'],
            ['name' => 'Work in progress', 'status' => 'Draft'],
        ]]))->assertCreated()
            ->assertJsonPath('course.chapters.0.description', 'Start here')
            ->assertJsonPath('course.chapters.1.status', 'Draft')
            ->json('course.id');

        $this->postJson(self::BASE.'/courses', $this->payload($this->category('Other'), ['title' => 'X', 'chapters' => [['name' => 'A', 'status' => 'Archived']]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['chapters.0.status']);

        $this->actAs(Role::Student);
        $this->getJson(self::BASE."/courses/{$course}")->assertOk()
            ->assertJsonCount(1, 'course.chapters')
            ->assertJsonPath('course.chapters.0.name', 'Live module');
    }

    private function batch(string $name): int
    {
        return Batch::create(['name' => $name, 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50])->id;
    }

    public function test_courses_and_batches_map_many_to_many_from_either_side(): void
    {
        $this->actAs(Role::Admin);
        $cat = $this->category();
        $b1 = $this->batch('Batch 1');
        $b2 = $this->batch('Batch 2');

        $c1 = $this->postJson(self::BASE.'/courses', $this->payload($cat, ['batch_ids' => [$b1, $b2]]))->assertCreated()
            ->assertJsonCount(2, 'course.batches')->json('course.id');
        $c2 = $this->postJson(self::BASE.'/courses', $this->payload($cat, ['title' => 'Aptitude', 'batch_ids' => [$b1]]))->assertCreated()->json('course.id');

        $this->getJson(self::BASE.'/batches')->assertOk()->assertJsonCount(2, 'batches.0.courses');

        // Re-mapping from the batch side replaces its courses only.
        $this->putJson(self::BASE."/batches/{$b1}", ['name' => 'Batch 1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50, 'course_ids' => [$c2]])
            ->assertOk()->assertJsonCount(1, 'batch.courses')->assertJsonPath('batch.courses.0.title', 'Aptitude');
        $this->getJson(self::BASE."/courses/{$c1}")->assertJsonCount(1, 'course.batches')->assertJsonPath('course.batches.0.name', 'Batch 2');

        // Omitting batch_ids leaves the mapping alone; an empty list clears it.
        $this->putJson(self::BASE."/courses/{$c1}", $this->payload($cat))->assertJsonCount(1, 'course.batches');
        $this->putJson(self::BASE."/courses/{$c1}", $this->payload($cat, ['batch_ids' => []]))->assertJsonCount(0, 'course.batches');

        $this->postJson(self::BASE.'/courses', $this->payload($cat, ['title' => 'Bad', 'batch_ids' => [999]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['batch_ids.0']);
    }
}
