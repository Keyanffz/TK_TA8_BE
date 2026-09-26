<?php

namespace Database\Factories;

use App\Enums\TargetPengumuman;
use App\Models\Pengumuman;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Pengumuman>
 */
class PengumumanFactory extends Factory
{
    private const CONTOH = [
        ['Libur Maulid Nabi Muhammad SAW', 'Kegiatan belajar diliburkan pada hari Maulid Nabi. Anak-anak masuk kembali keesokan harinya pukul 07.30.'],
        ['Pemeriksaan kesehatan gigi dari Puskesmas', 'Petugas Puskesmas akan memeriksa gigi anak-anak di sekolah. Mohon anak sarapan dan menggosok gigi sebelum berangkat.'],
        ['Pengumpulan fotokopi Kartu Keluarga', 'Untuk pembaruan data Dapodik, mohon kirimkan fotokopi Kartu Keluarga terbaru lewat wali kelas paling lambat hari Jumat.'],
        ['Kegiatan manasik haji cilik', 'Anak-anak mengikuti manasik haji cilik di halaman sekolah. Mohon anak memakai baju putih dan membawa bekal minum.'],
        ['Pertemuan wali murid awal semester', 'Pertemuan wali murid membahas program semester ini diadakan di aula sekolah pukul 09.00. Mohon kehadiran Bapak/Ibu.'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$judul, $isi] = fake()->randomElement(self::CONTOH);

        return [
            'judul' => $judul,
            'slug' => Str::slug($judul).'-'.fake()->unique()->numerify('####'),
            'isi' => '<p>'.$isi.'</p>',
            'lampiran_path' => null,
            'target' => TargetPengumuman::Semua,
            'is_publik' => false,
            'is_pinned' => false,
            'penulis_id' => User::factory()->superAdmin(),
            'published_at' => '2026-09-20 08:00:00',
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['published_at' => null]);
    }
}
