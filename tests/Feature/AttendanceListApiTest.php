<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceListApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/web/attendance/list';

    private Batch $b1;

    private Batch $b2;

    private User $priya;

    private User $arun;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $b = fn (string $n) => Batch::create(['name' => $n, 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->b1 = $b('Batch 1');
        $this->b2 = $b('Batch 2');
        $college = College::create(['code' => 'FCM', 'name' => 'Fatima College', 'city' => 'Madurai']);

        $this->priya = User::factory()->create(['name' => 'Priya', 'batch_id' => $this->b1->id, 'college_id' => $college->id, 'org' => 'Fatima College']);
        $this->arun = User::factory()->create(['name' => 'Arun', 'batch_id' => $this->b1->id]);
        $this->other = User::factory()->create(['name' => 'Other', 'batch_id' => $this->b2->id]);

        $marker = User::factory()->role(Role::Admin)->create(['name' => 'Admin']);
        $row = fn (User $u, $date, string $status, ?string $reason = null) => Attendance::create([
            'student_id' => $u->id, 'att_date' => $date, 'status' => $status, 'reason' => $reason, 'marked_by' => $marker->id,
        ]);
        $row($this->priya, today(), 'Present');
        $row($this->priya, today()->subDay(), 'Absent', 'Fever');
        $row($this->arun, today(), 'Manual', 'Device issue');
        $row($this->other, today(), 'Present');
        $row($this->priya, today()->subDays(200), 'Present'); // outside the default window

        Sanctum::actingAs($marker);
    }

    public function test_lists_attendance_with_the_student_batch_and_marker_newest_first(): void
    {
        $res = $this->getJson(self::URL)->assertOk()->assertJsonCount(4, 'records');

        $first = $res->json('records.0');
        $this->assertSame(today()->toDateString(), $first['date']);
        $this->assertSame('Admin', $first['marked_by_name']);

        $priyaAbsent = collect($res->json('records'))->firstWhere('reason', 'Fever');
        $this->assertSame('Priya', $priyaAbsent['name']);
        $this->assertSame('Absent', $priyaAbsent['status']);
        $this->assertSame('Batch 1', $priyaAbsent['batch']);
        $this->assertSame($this->priya->public_id, $priyaAbsent['student_id']);
    }

    public function test_filters_by_date_batch_college_student_and_status(): void
    {
        $this->getJson(self::URL.'?date_from='.today()->subDays(250)->toDateString())->assertJsonCount(5, 'records');
        $this->getJson(self::URL.'?date_from='.today()->toDateString().'&date_to='.today()->toDateString())->assertJsonCount(3, 'records');
        $this->getJson(self::URL.'?batch_id='.$this->b2->id)->assertJsonCount(1, 'records')->assertJsonPath('records.0.name', 'Other');
        $this->getJson(self::URL.'?college_id='.$this->priya->college_id)->assertJsonCount(2, 'records');
        $this->getJson(self::URL.'?student_id='.urlencode($this->arun->public_id))->assertJsonCount(1, 'records')->assertJsonPath('records.0.status', 'Manual');
        $this->getJson(self::URL.'?status=Absent')->assertJsonCount(1, 'records');
        $this->getJson(self::URL.'?status=Maybe')->assertUnprocessable();
        $this->getJson(self::URL.'?date_from='.today()->toDateString().'&date_to='.today()->subDay()->toDateString())->assertUnprocessable();
    }

    public function test_a_mentor_sees_only_their_own_batch(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Mentor)->create(['batch_id' => $this->b1->id]));
        $this->getJson(self::URL)->assertOk()->assertJsonCount(3, 'records');
        $this->getJson(self::URL.'?batch_id='.$this->b2->id)->assertOk()->assertJsonCount(0, 'records'); // cannot widen the scope

        Sanctum::actingAs(User::factory()->role(Role::Mentor)->create(['batch_id' => null]));
        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'records');
    }

    public function test_students_and_other_roles_cannot_use_the_list(): void
    {
        Sanctum::actingAs($this->priya);
        $this->getJson(self::URL)->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Institution)->create());
        $this->getJson(self::URL)->assertForbidden();
    }
}
