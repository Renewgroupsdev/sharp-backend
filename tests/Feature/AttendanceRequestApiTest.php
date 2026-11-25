<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web/attendance-requests';

    private User $student;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create(['name' => 'Batch 1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->student = User::factory()->create(['batch_id' => $this->batch->id]);
    }

    private function report(string $status, ?string $reason = null): int
    {
        Sanctum::actingAs($this->student);

        return $this->postJson(self::BASE, ['status' => $status, 'reason' => $reason])->assertCreated()
            ->assertJsonPath('request.status', 'Pending')->assertJsonPath('request.requested_status', $status)->json('request.id');
    }

    public function test_student_reports_present_with_one_click_and_sees_only_their_own(): void
    {
        $this->report('Present');
        $other = User::factory()->create(['batch_id' => $this->batch->id]);

        $this->getJson(self::BASE)->assertOk()->assertJsonCount(1, 'requests')
            ->assertJsonPath('today', today()->toDateString())
            ->assertJsonPath('requests.0.student_id', $this->student->public_id)->assertJsonPath('requests.0.reason', null);

        Sanctum::actingAs($other);
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'requests');
    }

    public function test_absent_needs_a_reason(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson(self::BASE, ['status' => 'Absent'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson(self::BASE, ['status' => 'Absent', 'reason' => 'ok'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson(self::BASE, ['status' => 'Maybe'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->report('Absent', 'Fever, doctor advised rest');
    }

    public function test_a_pending_report_can_be_changed_but_stays_one_row(): void
    {
        $this->report('Present');
        $this->report('Absent', 'Bus broke down');

        $this->assertDatabaseCount('attendance_requests', 1);
        $this->assertDatabaseHas('attendance_requests', ['student_id' => $this->student->id, 'requested_status' => 'Absent', 'reason' => 'Bus broke down']);

        $this->report('Present');
        $this->assertDatabaseHas('attendance_requests', ['requested_status' => 'Present', 'reason' => null]);
    }

    public function test_cannot_report_when_today_is_already_marked(): void
    {
        Attendance::create(['student_id' => $this->student->id, 'att_date' => today(), 'status' => 'Manual', 'reason' => 'x']);
        Sanctum::actingAs($this->student);

        $this->postJson(self::BASE, ['status' => 'Present'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_admin_marks_present_and_the_student_cannot_change_it_afterwards(): void
    {
        $id = $this->report('Present');

        $admin = User::factory()->role(Role::Admin)->create();
        Sanctum::actingAs($admin);
        $this->getJson(self::BASE.'?status=Pending')->assertJsonCount(1, 'requests');

        $this->postJson(self::BASE."/{$id}/mark", ['status' => 'Present'])->assertOk()
            ->assertJsonPath('request.status', 'Resolved')->assertJsonPath('request.decision', 'Present')->assertJsonPath('request.resolved_by', $admin->name);
        $this->assertDatabaseHas('attendance', ['student_id' => $this->student->id, 'status' => 'Present', 'marked_by' => $admin->id]);
        $this->getJson(self::BASE.'?status=Pending')->assertJsonCount(0, 'requests');

        Sanctum::actingAs($this->student);
        $this->postJson(self::BASE, ['status' => 'Absent', 'reason' => 'changed my mind'])->assertUnprocessable();
    }

    public function test_admin_can_mark_a_present_report_absent_and_remark_it_later(): void
    {
        $id = $this->report('Present');

        Sanctum::actingAs(User::factory()->role(Role::Ob)->create());
        $this->postJson(self::BASE."/{$id}/mark", ['status' => 'Absent', 'note' => 'Not seen in class'])->assertOk()
            ->assertJsonPath('request.decision', 'Absent')->assertJsonPath('request.resolution_note', 'Not seen in class');
        $this->assertDatabaseHas('attendance', ['student_id' => $this->student->id, 'status' => 'Absent', 'reason' => 'Not seen in class']);

        $this->postJson(self::BASE."/{$id}/mark", ['status' => 'Present'])->assertOk()->assertJsonPath('request.decision', 'Present');
        $this->assertDatabaseCount('attendance', 1);
        $this->assertDatabaseHas('attendance', ['student_id' => $this->student->id, 'status' => 'Present']);
    }

    public function test_marking_an_absent_report_present_is_a_manual_mark_and_absent_keeps_the_students_reason(): void
    {
        $id = $this->report('Absent', 'Fever');
        $other = User::factory()->create(['batch_id' => $this->batch->id]);
        Sanctum::actingAs($other);
        $second = $this->postJson(self::BASE, ['status' => 'Absent', 'reason' => 'Family function'])->json('request.id');

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->postJson(self::BASE."/{$id}/mark", ['status' => 'Present'])->assertOk();
        $this->assertDatabaseHas('attendance', ['student_id' => $this->student->id, 'status' => 'Manual']);

        $this->postJson(self::BASE."/{$second}/mark", ['status' => 'Absent'])->assertOk();
        $this->assertDatabaseHas('attendance', ['student_id' => $other->id, 'status' => 'Absent', 'reason' => 'Family function']);
    }

    public function test_list_filters_and_orders_pending_first(): void
    {
        $this->report('Present');
        $done = User::factory()->create(['batch_id' => $this->batch->id]);
        Sanctum::actingAs($done);
        $doneId = $this->postJson(self::BASE, ['status' => 'Absent', 'reason' => 'Fever'])->json('request.id');

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->postJson(self::BASE."/{$doneId}/mark", ['status' => 'Absent'])->assertOk();

        $this->getJson(self::BASE)->assertJsonPath('requests.0.status', 'Pending')->assertJsonPath('requests.1.status', 'Resolved');
        $this->getJson(self::BASE.'?requested_status=Absent')->assertJsonCount(1, 'requests');
        $this->getJson(self::BASE.'?date='.today()->toDateString())->assertJsonCount(2, 'requests');
        $this->getJson(self::BASE.'?date='.today()->subDay()->toDateString())->assertJsonCount(0, 'requests');
    }

    public function test_mentors_only_handle_their_own_batch_and_students_cannot_mark(): void
    {
        $id = $this->report('Present');

        $otherBatch = Batch::create(['name' => 'Batch 2', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        Sanctum::actingAs(User::factory()->role(Role::Mentor)->create(['batch_id' => $otherBatch->id]));
        $this->getJson(self::BASE)->assertJsonCount(0, 'requests');
        $this->postJson(self::BASE."/{$id}/mark", ['status' => 'Present'])->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Mentor)->create(['batch_id' => $this->batch->id]));
        $this->getJson(self::BASE)->assertJsonCount(1, 'requests');
        $this->postJson(self::BASE."/{$id}/mark", ['status' => 'Present'])->assertOk();

        Sanctum::actingAs($this->student);
        $this->postJson(self::BASE."/{$id}/mark", ['status' => 'Present'])->assertForbidden();
    }

    public function test_only_students_can_send_reports(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

        $this->postJson(self::BASE, ['status' => 'Present'])->assertForbidden();
    }
}
