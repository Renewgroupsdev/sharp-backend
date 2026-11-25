<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web/enrollments';

    private Course $course;

    private Batch $running;

    private Batch $upcoming;

    private Batch $finished;

    protected function setUp(): void
    {
        parent::setUp();

        $make = fn (string $name, $start, $end, string $status = 'running') => Batch::create(['name' => $name, 'start_date' => $start, 'end_date' => $end, 'seats' => 50, 'status' => $status]);
        $this->running = $make('Running', today()->subMonth(), today()->addMonth());
        $this->upcoming = $make('Upcoming', today()->addMonth(), today()->addMonths(5), 'enrolling');
        $this->finished = $make('Finished', today()->subMonths(6), today()->subMonths(1), 'closed');

        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'mentors' => ['Mentor One']]);
        $this->course->batches()->sync([$this->running->id, $this->upcoming->id, $this->finished->id]);

        User::factory()->count(3)->create(['batch_id' => $this->running->id]);
        User::factory()->role(Role::Mentor)->create(['batch_id' => $this->running->id, 'name' => 'Mentor One']);
        User::factory()->role(Role::Mentor)->create(['batch_id' => $this->running->id, 'name' => 'Batch Only']); // in the batch, but not picked on the course
        User::factory()->create(['batch_id' => $this->upcoming->id]);

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
    }

    private function row(string $batch): array
    {
        return collect($this->getJson(self::BASE)->assertOk()->json('enrollments'))->firstWhere('batch', $batch);
    }

    public function test_lists_each_course_batch_pair_with_counts_dates_and_derived_status(): void
    {
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(3, 'enrollments');

        $running = $this->row('Running');
        $this->assertSame('Communication', $running['course']);
        $this->assertSame('CRS-'.str_pad((string) $this->course->id, 3, '0', STR_PAD_LEFT), $running['course_code']);
        $this->assertSame(3, $running['students_count']);
        $this->assertSame(1, $running['mentors_count']);
        $this->assertSame(today()->subMonth()->toDateString(), $running['start_date']);
        $this->assertSame('Ongoing', $running['status']);
        $this->assertSame('Upcoming', $this->row('Upcoming')['status']);
        $this->assertSame('Completed', $this->row('Finished')['status']);
    }

    public function test_filters(): void
    {
        $this->getJson(self::BASE.'?status=Ongoing')->assertJsonCount(1, 'enrollments')->assertJsonPath('enrollments.0.batch', 'Running');
        $this->getJson(self::BASE.'?status=Upcoming')->assertJsonCount(1, 'enrollments')->assertJsonPath('enrollments.0.batch', 'Upcoming');
        $this->getJson(self::BASE.'?status=Completed')->assertJsonCount(1, 'enrollments')->assertJsonPath('enrollments.0.batch', 'Finished');
        $this->getJson(self::BASE.'?q=commun')->assertJsonCount(3, 'enrollments');
        $this->getJson(self::BASE.'?q=nothing')->assertJsonCount(0, 'enrollments');
        $this->getJson(self::BASE.'?batch_id='.$this->upcoming->id)->assertJsonCount(1, 'enrollments');
        $this->getJson(self::BASE.'?status=Soon')->assertUnprocessable();
    }

    public function test_members_lists_the_batch_students_and_the_mentors_picked_on_the_course(): void
    {
        $id = $this->row('Running')['id'];

        $this->getJson(self::BASE."/{$id}/members")->assertOk()
            ->assertJsonCount(3, 'students')->assertJsonCount(1, 'mentors')->assertJsonPath('mentors.0.name', 'Mentor One');
        $this->getJson(self::BASE.'/99999/members')->assertNotFound();
    }

    public function test_deleted_courses_are_not_listed(): void
    {
        $this->course->delete();

        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'enrollments');
    }

    public function test_enrolling_batches_is_idempotent_and_removing_unmaps_them(): void
    {
        $other = Batch::create(['name' => 'Other', 'start_date' => today(), 'end_date' => today()->addMonth(), 'seats' => 10]);

        $this->postJson(self::BASE, ['course_id' => $this->course->id, 'batch_ids' => [$other->id, $this->running->id]])
            ->assertCreated()->assertJsonCount(2, 'enrollments');
        $this->assertDatabaseCount('batch_course', 4);

        $id = $this->row('Other')['id'];
        $this->deleteJson(self::BASE."/{$id}")->assertOk();
        $this->assertDatabaseCount('batch_course', 3);

        $this->postJson(self::BASE, ['course_id' => $this->course->id, 'batch_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['batch_ids']);
        $this->postJson(self::BASE, ['course_id' => 999, 'batch_ids' => [$other->id]])->assertUnprocessable()->assertJsonValidationErrors(['course_id']);
    }

    public function test_mentors_see_only_their_batch_and_cannot_write_and_students_are_blocked(): void
    {
        $mentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $this->running->id]);
        Sanctum::actingAs($mentor);

        $this->getJson(self::BASE)->assertOk()->assertJsonCount(1, 'enrollments')->assertJsonPath('enrollments.0.batch', 'Running');
        $this->postJson(self::BASE, ['course_id' => $this->course->id, 'batch_ids' => [$this->running->id]])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['batch_id' => $this->running->id]));
        $this->getJson(self::BASE)->assertForbidden();
    }
}
