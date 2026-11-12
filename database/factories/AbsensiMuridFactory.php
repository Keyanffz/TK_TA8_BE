<?php

namespace Database\Factories;

use App\Enums\StatusAbsensiMurid;
use App\Models\AbsensiMurid;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbsensiMurid>
 */
class AbsensiMuridFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'murid_id' => Murid::factory(),
            'kelas_id' => Kelas::factory(),
            'tanggal' => today()->toDateString(),
            'status' => StatusAbsensiMurid::Hadir,
            'keterangan' => null,
            'dicatat_oleh' => User::factory(),
        ];
    }
}
