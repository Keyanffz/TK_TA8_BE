<?php

namespace Database\Factories;

use App\Models\ElemenPenilaian;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ElemenPenilaian>
 */
class ElemenPenilaianFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => 'EL'.fake()->unique()->numerify('###'),
            'nama' => 'Jati Diri',
            'deskripsi' => null,
            'urutan' => fake()->numberBetween(1, 10),
            'is_aktif' => true,
        ];
    }
}
