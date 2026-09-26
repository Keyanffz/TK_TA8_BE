<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\StatusAkun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->freeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Guru,
            'status' => StatusAkun::Aktif,
            'no_hp' => fake()->numerify('081#########'),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => ['role' => Role::SuperAdmin]);
    }

    public function waliMurid(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::WaliMurid,
            'password' => null,
            'google_id' => (string) fake()->unique()->numerify('1##################'),
        ]);
    }

    public function status(StatusAkun $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
