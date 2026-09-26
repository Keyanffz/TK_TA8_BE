<?php

namespace Database\Factories;

use App\Models\GaleriAlbum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GaleriAlbum>
 */
class GaleriAlbumFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $judul = 'Peringatan Hari Anak Nasional';

        return [
            'judul' => $judul,
            'slug' => Str::slug($judul).'-'.fake()->unique()->numerify('####'),
            'deskripsi' => 'Lomba mewarnai dan pentas lagu anak di halaman sekolah.',
            'cover_path' => null,
            'tanggal' => '2026-07-23',
            'is_publik' => true,
        ];
    }
}
