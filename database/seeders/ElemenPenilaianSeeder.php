<?php

namespace Database\Seeders;

use App\Models\ElemenPenilaian;
use Illuminate\Database\Seeder;

/**
 * Tiga elemen capaian pembelajaran Kurikulum Merdeka PAUD (A2.8). Kepala Sekolah bisa
 * menambah atau mengubahnya nanti, jadi elemen yang sudah ada tidak ditimpa.
 */
class ElemenPenilaianSeeder extends Seeder
{
    public function run(): void
    {
        $elemen = [
            [
                'kode' => 'NAB',
                'nama' => 'Nilai Agama & Budi Pekerti',
                'deskripsi' => 'Anak mengenal ajaran pokok agamanya, membiasakan ibadah harian, dan berperilaku baik kepada diri sendiri, sesama, dan alam sekitar.',
            ],
            [
                'kode' => 'JD',
                'nama' => 'Jati Diri',
                'deskripsi' => 'Anak mengenali identitas diri dan keluarganya, belajar mengelola emosi, mulai mandiri, dan mengembangkan kemampuan motorik kasar dan halus.',
            ],
            [
                'kode' => 'LITERASI_STEAM',
                'nama' => 'Dasar-dasar Literasi, Matematika, Sains, Teknologi, Rekayasa & Seni',
                'deskripsi' => 'Anak mengenal simbol dan huruf, berhitung sederhana, mengamati dan mencoba hal baru, serta berkarya lewat kegiatan seni.',
            ],
        ];

        foreach ($elemen as $urutan => $data) {
            ElemenPenilaian::query()->firstOrCreate(
                ['kode' => $data['kode']],
                [...$data, 'urutan' => $urutan + 1, 'is_aktif' => true],
            );
        }
    }
}
