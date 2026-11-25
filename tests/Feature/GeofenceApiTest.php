<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Attendance reports ignore location: only the institute form still stores a geofence. */
class GeofenceApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private const LAT = 9.9252;

    private const LNG = 78.1198;

    private College $college;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->college = College::create(['code' => 'FCM', 'name' => 'Fatima College', 'city' => 'Madurai', 'lat' => self::LAT, 'lng' => self::LNG, 'geofence_radius_m' => 200]);
        $batch = Batch::create(['name' => 'Batch 1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->student = User::factory()->create(['batch_id' => $batch->id, 'college_id' => $this->college->id]);
    }

    public function test_a_present_report_needs_no_location_even_when_the_college_has_a_geofence(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson(self::BASE.'/attendance-requests', ['status' => 'Present'])->assertCreated()
            ->assertJsonPath('request.requested_status', 'Present')->assertJsonPath('request.status', 'Pending');
    }

    public function test_a_location_sent_by_an_old_app_is_ignored_not_enforced(): void
    {
        Sanctum::actingAs($this->student);

        // Far outside the 200 m fence: once refused, now just a report waiting for the admin.
        $this->postJson(self::BASE.'/attendance-requests', ['status' => 'Present', 'lat' => self::LAT + 0.5, 'lng' => self::LNG])->assertCreated();
    }

    public function test_the_admin_still_decides_the_report(): void
    {
        Sanctum::actingAs($this->student);
        $id = $this->postJson(self::BASE.'/attendance-requests', ['status' => 'Present'])->json('request.id');

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->postJson(self::BASE."/attendance-requests/{$id}/mark", ['status' => 'Absent', 'note' => 'Not seen in class'])->assertOk()
            ->assertJsonPath('request.decision', 'Absent');
        $this->assertDatabaseHas('attendance', ['student_id' => $this->student->id, 'status' => 'Absent']);
    }

    public function test_the_attendance_list_no_longer_carries_a_geofence(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson(self::BASE.'/attendance-requests')->assertOk()->assertJsonMissingPath('geofence');
    }

    public function test_the_college_form_sets_and_clears_the_geofence(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $url = self::BASE.'/colleges/'.$this->college->id;

        $this->putJson($url, ['name' => 'Fatima College', 'lat' => 9.95, 'lng' => 78.12, 'geofence_radius_m' => 350])->assertOk()
            ->assertJsonPath('institute.lat', 9.95)->assertJsonPath('institute.geofence_radius_m', 350);

        $this->putJson($url, ['name' => 'Fatima College', 'lat' => 9.95])->assertUnprocessable()->assertJsonValidationErrors(['lng']);
        $this->putJson($url, ['name' => 'Fatima College', 'lat' => 9.95, 'lng' => 78.12, 'geofence_radius_m' => 5])->assertUnprocessable()->assertJsonValidationErrors(['geofence_radius_m']);

        $this->putJson($url, ['name' => 'Fatima College', 'lat' => null, 'lng' => null])->assertOk()
            ->assertJsonPath('institute.lat', null)->assertJsonPath('institute.lng', null)->assertJsonPath('institute.geofence_radius_m', 350);
    }
}
