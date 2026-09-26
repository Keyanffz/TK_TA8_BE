<?php

namespace Database\Factories;

use App\Enums\StatusTagihan;
use App\Models\JenisTagihan;
use App\Models\Murid;
use App\Models\Tagihan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Tagihan>
 */
class TagihanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periode = Carbon::parse('2026-09-01');

        return [
            'kode' => 'INV-'.$periode->format('Ym').'-'.fake()->unique()->numerify('#####'),
            'murid_id' => Murid::factory(),
            'jenis_tagihan_id' => JenisTagihan::factory(),
            'tahun_ajaran_id' => fn (array $atribut) => JenisTagihan::query()->findOrFail($atribut['jenis_tagihan_id'])->tahun_ajaran_id,
            'periode' => $periode->toDateString(),
            'nominal' => 150000,
            'potongan' => 0,
            'total' => 150000,
            'jatuh_tempo' => $periode->copy()->day(10)->toDateString(),
            'status' => StatusTagihan::BelumBayar,
            'lunas_at' => null,
            'dibuat_oleh' => null,
            'catatan' => null,
        ];
    }

    public function lunas(): static
    {
        return $this->state(fn (): array => [
            'status' => StatusTagihan::Lunas,
            'lunas_at' => '2026-09-08 10:15:00',
        ]);
    }
}
