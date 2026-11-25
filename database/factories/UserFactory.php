<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'public_id' => 'US-'.fake()->unique()->numerify('####'),
            'name' => $name,
            'username' => fake()->unique()->userName(),
            'password' => 'password',
            'must_change_password' => false,
            'role' => Role::Student,
            'status' => UserStatus::Active,
            'avatar_initials' => 'XX',
            'avatar_color' => '#0E7A4E',
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }
}
