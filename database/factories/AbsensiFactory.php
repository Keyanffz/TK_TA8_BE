<?php

namespace Database\Factories;

use App\Enums\JenisAbsensi;
use App\Enums\StatusAbsensi;
use App\Models\Absensi;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absensi>
 */
class AbsensiFactory extends Factory
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
            'status' => StatusAbsensi::Hadir,
            'waktu' => today()->setTime(6, 55),
            'latitude' => -6.9903,
            'longitude' => 110.4229,
            'akurasi_meter' => 12,
            'jarak_meter' => 18,
            'foto_path' => null,
        ];
    }

    public function pulang(): static
    {
        return $this->state(fn (): array => [
            'jenis' => JenisAbsensi::Pulang,
            'status' => null,
            'waktu' => today()->setTime(12, 5),
        ]);
    }

    public function tidakHadir(): static
    {
        return $this->state(fn (): array => [
            'status' => StatusAbsensi::TidakHadir,
            'waktu' => null,
            'latitude' => null,
            'longitude' => null,
            'akurasi_meter' => null,
            'jarak_meter' => null,
        ]);
    }
}
