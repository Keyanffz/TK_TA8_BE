<?php

namespace Database\Factories;

use App\Enums\JenisDokumen;
use App\Models\Pendaftaran;
use App\Models\PendaftaranDokumen;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PendaftaranDokumen>
 */
class PendaftaranDokumenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pendaftaran_id' => Pendaftaran::factory(),
            'jenis' => JenisDokumen::AktaKelahiran,
            'path' => 'ppdb/'.Str::uuid().'.jpg',
        ];
    }
}
