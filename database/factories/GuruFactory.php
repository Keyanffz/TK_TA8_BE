<?php

namespace Database\Factories;

use App\Enums\JenisKelamin;
use App\Enums\LevelIzin;
use App\Enums\ModulIzin;
use App\Models\Guru;
use App\Models\User;
use Database\Factories\Concerns\MembuatAlamatSemarang;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guru>
 */
class GuruFactory extends Factory
{
    use MembuatAlamatSemarang;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nip' => null,
            'nuptk' => fake()->numerify('################'),
            'jenis_kelamin' => JenisKelamin::P,
            'tempat_lahir' => fake()->randomElement(['Semarang', 'Kendal', 'Demak', 'Ungaran', 'Salatiga']),
            'tanggal_lahir' => fake()->dateTimeBetween('-50 years', '-23 years')->format('Y-m-d'),
            'alamat' => $this->alamatSemarang(),
            'pendidikan_terakhir' => fake()->randomElement(['S1 PG-PAUD', 'S1 PGSD', 'D3 PAUD', 'S1 Psikologi']),
            'jabatan' => 'Guru Kelas',
            'foto_path' => null,
            'tampil_di_landing' => false,
        ];
    }

    public function izin(ModulIzin $modul, LevelIzin $level): static
    {
        return $this->afterCreating(fn (Guru $guru) => $guru->izin()->create(['modul' => $modul, 'level' => $level]));
    }

    /**
     * Petugas keuangan: izin kelola keuangan (preset Bendahara).
     */
    public function kelolaKeuangan(): static
    {
        return $this->izin(ModulIzin::Keuangan, LevelIzin::Kelola);
    }
}
