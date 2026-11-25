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

class AssessmentAttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private Batch $batch;

    private Assessment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->batch = Batch::create(['name' => 'Batch A', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'seats' => 20]);
        Course::create([
            'title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft Skills'])->id, 'hours' => 10, 'status' => 'Published',
        ])->batches()->sync([$this->batch->id]);
        $this->assignment = Assessment::create([
            'type' => 'assignment', 'title' => 'Essay', 'course' => 'Communication', 'status' => 'published',
            'due_date' => now()->addDays(10)->toDateString(), 'max_marks' => 50, 'min_percent' => 60,
        ]);
    }

    private function actAs(Role $role, array $attributes = []): User
    {
        $user = User::factory()->role($role)->create($attributes);
        Sanctum::actingAs($user);

        return $user;
    }

    private function upload(string $name = 'brief.pdf'): \Illuminate\Testing\TestResponse
    {
        return $this->post(self::BASE."/assessments/{$this->assignment->id}/attachment", ['file' => UploadedFile::fake()->create($name, 120, 'application/pdf')], ['Accept' => 'application/json']);
    }

    public function test_admin_uploads_a_brief_and_its_path_is_stored(): void
    {
        $this->actAs(Role::Admin);

        $this->upload()->assertOk()
            ->assertJsonPath('assessment.attachment.name', 'brief.pdf')
            ->assertJsonPath('assessment.attachment.available', true)
            ->assertJsonMissingPath('assessment.attachment.path');

        $row = $this->assignment->fresh();
        $this->assertNotNull($row->attachment_path);
        $this->assertSame('local', $row->attachment_disk);
        Storage::disk('local')->assertExists($row->attachment_path);
    }

    public function test_replacing_the_brief_deletes_the_old_file(): void
    {
        $this->actAs(Role::Admin);
        $this->upload('one.pdf')->assertOk();
        $first = $this->assignment->fresh()->attachment_path;

        $this->upload('two.pdf')->assertOk();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($this->assignment->fresh()->attachment_path);
    }

    public function test_upload_rejects_a_disallowed_type_and_a_quiz(): void
    {
        $this->actAs(Role::Admin);
        $this->post(self::BASE."/assessments/{$this->assignment->id}/attachment", ['file' => UploadedFile::fake()->create('run.exe', 10)], ['Accept' => 'application/json'])->assertUnprocessable();

        $quiz = Assessment::create(['type' => 'quiz', 'title' => 'Quiz', 'course' => 'Communication', 'status' => 'published']);
        $this->post(self::BASE."/assessments/{$quiz->id}/attachment", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_mentor_cannot_upload_but_can_open(): void
    {
        $this->actAs(Role::Admin);
        $this->upload()->assertOk();

        $this->actAs(Role::Mentor);
        $this->upload()->assertForbidden();
        $this->get(self::BASE."/assessments/{$this->assignment->id}/attachment")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_student_of_the_course_opens_it_and_others_cannot(): void
    {
        $this->actAs(Role::Admin);
        $this->upload()->assertOk();

        $this->actAs(Role::Student, ['batch_id' => $this->batch->id]);
        $this->get(self::BASE."/assessments/{$this->assignment->id}/attachment")->assertOk();
        $this->getJson(self::BASE.'/my/assessments')->assertJsonPath('assessments.0.attachment.name', 'brief.pdf');

        $other = Batch::create(['name' => 'Batch B', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31', 'seats' => 20]);
        $this->actAs(Role::Student, ['batch_id' => $other->id]);
        $this->get(self::BASE."/assessments/{$this->assignment->id}/attachment")->assertNotFound();
    }

    public function test_student_cannot_open_a_draft_brief(): void
    {
        $this->actAs(Role::Admin);
        $this->upload()->assertOk();
        $this->assignment->update(['status' => 'draft']);

        $this->actAs(Role::Student, ['batch_id' => $this->batch->id]);
        $this->get(self::BASE."/assessments/{$this->assignment->id}/attachment")->assertNotFound();
    }

    public function test_remove_and_delete_clean_up_the_stored_file(): void
    {
        $this->actAs(Role::Admin);
        $this->upload()->assertOk();
        $path = $this->assignment->fresh()->attachment_path;

        $this->deleteJson(self::BASE."/assessments/{$this->assignment->id}/attachment")->assertOk()->assertJsonPath('assessment.attachment', null);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->assignment->fresh()->attachment_path);

        $this->upload()->assertOk();
        $path = $this->assignment->fresh()->attachment_path;
        $this->deleteJson(self::BASE."/assessments/{$this->assignment->id}")->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_switching_an_assignment_to_a_quiz_drops_the_file(): void
    {
        $this->actAs(Role::Admin);
        $this->upload()->assertOk();
        $path = $this->assignment->fresh()->attachment_path;

        $this->putJson(self::BASE."/assessments/{$this->assignment->id}", ['type' => 'quiz', 'title' => 'Essay', 'course' => 'Communication'])->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->assignment->fresh()->attachment_name);
    }
}
