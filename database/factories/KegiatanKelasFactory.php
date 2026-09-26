<?php

namespace Database\Factories;

use App\Models\Guru;
use App\Models\KegiatanKelas;
use App\Models\Kelas;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KegiatanKelas>
 */
class KegiatanKelasFactory extends Factory
{
    private const CONTOH = [
        ['Tanaman', 'Menanam biji kacang hijau', 'Anak-anak menanam biji kacang hijau di gelas plastik dan akan mengamati pertumbuhannya setiap pagi.'],
        ['Aku Hebat', 'Praktik wudu dan salat duha', 'Anak-anak berlatih urutan wudu dengan pendampingan guru, lalu salat duha berjamaah.'],
        ['Lingkunganku', 'Membuat kolase daun kering', 'Anak-anak mengumpulkan daun kering di halaman sekolah dan menempelkannya menjadi gambar binatang.'],
        ['Keluargaku', 'Bercerita tentang anggota keluarga', 'Setiap anak menunjukkan foto keluarga dan menyebutkan nama anggota keluarganya.'],
        ['Kendaraan', 'Bermain peran di terminal mini', 'Anak-anak bergantian menjadi sopir, kondektur, dan penumpang untuk mengenal aturan antre.'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$tema, $judul, $deskripsi] = fake()->randomElement(self::CONTOH);

        return [
            'kelas_id' => Kelas::factory(),
            'guru_id' => Guru::factory(),
            'tanggal' => fake()->dateTimeBetween('2026-07-20', '2026-09-25')->format('Y-m-d'),
            'tema' => $tema,
            'judul' => $judul,
            'deskripsi' => $deskripsi,
        ];
    }
}
