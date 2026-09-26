<?php

namespace Database\Factories;

use App\Enums\PeriodeTagihan;
use App\Models\JenisTagihan;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JenisTagihan>
 */
class JenisTagihanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tahun_ajaran_id' => TahunAjaran::factory(),
            'nama' => 'SPP',
            'deskripsi' => 'Sumbangan pembinaan pendidikan bulanan.',
            'nominal' => 150000,
            'periode' => PeriodeTagihan::Bulanan,
            'tingkat' => null,
            'is_aktif' => true,
        ];
    }

    public function sekali(): static
    {
        return $this->state(fn (): array => [
            'nama' => 'Seragam',
            'deskripsi' => 'Dua stel seragam harian dan satu stel seragam olahraga.',
            'nominal' => 350000,
            'periode' => PeriodeTagihan::Sekali,
        ]);
    }
}
