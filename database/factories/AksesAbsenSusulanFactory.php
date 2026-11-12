<?php

namespace Database\Factories;

use App\Enums\JenisAbsensi;
use App\Models\AksesAbsenSusulan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AksesAbsenSusulan>
 */
class AksesAbsenSusulanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tanggal' => today()->toDateString(),
            'jenis' => JenisAbsensi::Masuk,
            'catatan' => 'Wifi sekolah mati sejak pagi.',
            'diberikan_oleh' => User::factory()->superAdmin(),
            'berlaku_sampai' => today()->endOfDay(),
            'dipakai_at' => null,
        ];
    }
}
