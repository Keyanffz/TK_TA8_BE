<?php

namespace Database\Factories;

use App\Models\ElemenPenilaian;
use App\Models\Rapor;
use App\Models\RaporDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaporDetail>
 */
class RaporDetailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rapor_id' => Rapor::factory(),
            'elemen_penilaian_id' => ElemenPenilaian::factory(),
            'deskripsi' => null,
            'foto_path' => null,
        ];
    }
}
