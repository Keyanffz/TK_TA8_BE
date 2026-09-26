<?php

namespace Database\Factories;

use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TahunAjaran>
 */
class TahunAjaranFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Di bawah 2021 supaya tidak bentrok dengan tahun ajaran yang namanya ditulis langsung
        // di test dan DemoSeeder (2025/2026 ke atas); nama tahun ajaran unik.
        $tahun = fake()->unique()->numberBetween(1990, 2020);

        return [
            'nama' => $tahun.'/'.($tahun + 1),
            'tanggal_mulai' => $tahun.'-07-13',
            'tanggal_selesai' => ($tahun + 1).'-06-25',
            'semester_aktif' => 1,
            'is_aktif' => false,
        ];
    }

    public function aktif(): static
    {
        return $this->state(fn (): array => ['is_aktif' => true]);
    }
}
