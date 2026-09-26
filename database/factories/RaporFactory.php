<?php

namespace Database\Factories;

use App\Enums\StatusRapor;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Rapor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rapor>
 */
class RaporFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'murid_id' => Murid::factory(),
            'kelas_id' => Kelas::factory(),
            'tahun_ajaran_id' => fn (array $atribut) => Kelas::query()->findOrFail($atribut['kelas_id'])->tahun_ajaran_id,
            'semester' => 1,
            'tinggi_badan' => fake()->randomFloat(1, 100, 118),
            'berat_badan' => fake()->randomFloat(1, 15, 22),
            'catatan_guru' => null,
            'status' => StatusRapor::Draft,
            'catatan_revisi' => null,
            'dibuat_oleh' => Guru::factory(),
            'diajukan_at' => null,
            'disetujui_oleh' => null,
            'terbit_at' => null,
        ];
    }

    public function terbit(): static
    {
        return $this->state(fn (): array => [
            'status' => StatusRapor::Terbit,
            'diajukan_at' => '2026-12-10 13:00:00',
            'terbit_at' => '2026-12-18 09:00:00',
        ]);
    }
}
