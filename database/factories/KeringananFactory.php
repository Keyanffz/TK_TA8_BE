<?php

namespace Database\Factories;

use App\Enums\TipeKeringanan;
use App\Models\JenisTagihan;
use App\Models\Keringanan;
use App\Models\Murid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Keringanan>
 */
class KeringananFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'murid_id' => Murid::factory(),
            'jenis_tagihan_id' => JenisTagihan::factory(),
            'tipe' => TipeKeringanan::Persen,
            'nilai' => 50,
            'alasan' => fake()->randomElement([
                'Anak ketiga yang bersekolah di TK ini.',
                'Orang tua terdampak PHK, sesuai surat keterangan RT.',
                'Anak yatim.',
            ]),
            'berlaku_mulai' => '2026-07-01',
            'berlaku_sampai' => null,
            'dibuat_oleh' => null,
        ];
    }
}
