<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\College;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollegeShowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_can_read_one_institute_with_its_geofence(): void
    {
        $college = College::create(['name' => 'Fatima College', 'code' => 'FCM', 'lat' => 9.9252, 'lng' => 78.1198, 'geofence_radius_m' => 150]);
        Sanctum::actingAs(User::factory()->role(Role::Student)->create(['college_id' => $college->id]));

        $this->getJson("/api/web/colleges/{$college->id}")->assertOk()
            ->assertJsonPath('institute.name', 'Fatima College')
            ->assertJsonPath('institute.lat', 9.9252)
            ->assertJsonPath('institute.geofence_radius_m', 150);
    }

    public function test_an_unknown_institute_is_404_and_writes_stay_staff_only(): void
    {
        $college = College::create(['name' => 'Fatima College', 'code' => 'FCM']);
        Sanctum::actingAs(User::factory()->role(Role::Student)->create());

        $this->getJson('/api/web/colleges/999999')->assertNotFound();
        $this->putJson("/api/web/colleges/{$college->id}", ['name' => 'X'])->assertForbidden();
    }
}
