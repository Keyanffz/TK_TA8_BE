<?php

namespace Database\Factories;

use App\Enums\Tingkat;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kelas>
 */
class KelasFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tingkat = fake()->randomElement(Tingkat::cases());

        return [
            'tahun_ajaran_id' => TahunAjaran::factory(),
            'nama' => 'TK '.$tingkat->value.fake()->unique()->numberBetween(1, 99),
            'tingkat' => $tingkat,
            'wali_kelas_id' => null,
            'guru_pendamping_id' => null,
            'kapasitas' => 20,
        ];
    }
}
