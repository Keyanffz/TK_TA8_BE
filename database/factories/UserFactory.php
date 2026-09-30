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
            // Domain .test tidak bisa menerima email sungguhan, jadi email dari antrean lokal tidak nyasar.
            'email' => fake()->unique()->userName().'@guru.tkta8.test',
            // Guru tidak punya password; login lewat Google.
            'password' => null,
            'role' => Role::Guru,
            'status' => StatusAkun::Aktif,
            'no_hp' => fake()->numerify('081#########'),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::SuperAdmin,
            'email' => fake()->unique()->userName().'@tkta8.test',
            'password' => static::$password ??= Hash::make('password'),
        ]);
    }

    public function waliMurid(): static
    {
        return $this->state(fn (): array => [
            'role' => Role::WaliMurid,
            'email' => null,
            'password' => static::$password ??= Hash::make('password'),
            'username' => 'TA'.fake()->unique()->numerify('2026####'),
            'email_verified_at' => null,
        ]);
    }

    /**
     * Akun wali yang masih memakai password awal (tanggal lahir anak).
     */
    public function wajibGantiPassword(): static
    {
        return $this->state(fn (): array => ['wajib_ganti_password' => true]);
    }

    public function status(StatusAkun $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
