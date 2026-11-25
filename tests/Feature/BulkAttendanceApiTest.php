<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkAttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/web/attendance/bulk';

    private User $admin;

    private User $mentor;

    private User $priya;

    private User $meena;

    private User $arun;

    protected function setUp(): void
    {
        parent::setUp();

        $one = Batch::create(['name' => 'Batch 1', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $two = Batch::create(['name' => 'Batch 2', 'start_date' => today()->subMonth(), 'end_date' => today()->addMonth(), 'seats' => 50, 'status' => 'running']);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->mentor = User::factory()->role(Role::Mentor)->create(['batch_id' => $one->id]);
        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $one->id]);
        $this->meena = User::factory()->create(['name' => 'Meena', 'batch_id' => $one->id]);
        $this->arun = User::factory()->create(['name' => 'Arun', 'batch_id' => $two->id]);
    }

    public function test_staff_mark_many_students_in_one_go_and_each_is_told(): void
    {
        Sanctum::actingAs($this->admin);
        $day = today()->subDay()->toDateString();

        $this->postJson(self::URL, ['student_ids' => [$this->priya->public_id, $this->arun->public_id], 'status' => 'Manual', 'reason' => 'Workshop day', 'date' => $day])
            ->assertOk()->assertJsonPath('marked', 2);

        foreach ([$this->priya, $this->arun] as $s) {
            $row = Attendance::where('student_id', $s->id)->whereDate('att_date', $day)->firstOrFail();
            $this->assertSame('Manual', $row->status->value ?? $row->status);
            $this->assertSame('Workshop day', $row->reason);
            $this->assertSame($this->admin->id, $row->marked_by);
            $this->assertSame(1, Notification::where('user_id', $s->id)->where('type', 'attendance')->count());
        }

        // Marking the same people again for the same day rewrites the day, it does not duplicate it.
        $this->postJson(self::URL, ['student_ids' => [$this->priya->public_id], 'status' => 'Absent', 'reason' => 'Left early', 'date' => $day])->assertOk();
        $this->assertSame(1, Attendance::where('student_id', $this->priya->id)->whereDate('att_date', $day)->count());
    }

    public function test_the_request_is_checked_before_anything_is_saved(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(self::URL, ['student_ids' => [], 'status' => 'Present'])->assertUnprocessable()->assertJsonValidationErrors(['student_ids']);
        $this->postJson(self::URL, ['student_ids' => [$this->priya->public_id], 'status' => 'Absent'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson(self::URL, ['student_ids' => [$this->priya->public_id], 'status' => 'Present', 'date' => today()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->postJson(self::URL, ['student_ids' => [$this->priya->public_id, 'NOPE'], 'status' => 'Present'])->assertNotFound();

        $this->assertSame(0, Attendance::count()); // the unknown id stopped the whole batch
    }

    public function test_a_mentor_can_only_mark_their_own_batch_and_students_are_refused(): void
    {
        Sanctum::actingAs($this->mentor);
        $this->postJson(self::URL, ['student_ids' => [$this->priya->public_id, $this->meena->public_id], 'status' => 'Present'])->assertOk()->assertJsonPath('marked', 2);
        $this->postJson(self::URL, ['student_ids' => [$this->priya->public_id, $this->arun->public_id], 'status' => 'Present'])->assertForbidden();
        $this->assertSame(0, Attendance::where('student_id', $this->arun->id)->count());

        Sanctum::actingAs($this->priya);
        $this->postJson(self::URL, ['student_ids' => [$this->meena->public_id], 'status' => 'Present'])->assertForbidden();
    }
}
