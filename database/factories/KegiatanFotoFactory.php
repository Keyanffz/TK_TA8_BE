<?php

namespace Database\Factories;

use App\Models\KegiatanFoto;
use App\Models\KegiatanKelas;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KegiatanFoto>
 */
class KegiatanFotoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kegiatan_kelas_id' => KegiatanKelas::factory(),
            'path' => 'kegiatan/'.Str::uuid().'.jpg',
            'caption' => null,
            'urutan' => 0,
        ];
    }
}
