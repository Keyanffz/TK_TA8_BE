<?php

namespace Database\Factories;

use App\Models\GaleriAlbum;
use App\Models\GaleriFoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GaleriFoto>
 */
class GaleriFotoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'galeri_album_id' => GaleriAlbum::factory(),
            'path' => 'galeri/'.Str::uuid().'.jpg',
            'caption' => null,
            'urutan' => 0,
        ];
    }
}
