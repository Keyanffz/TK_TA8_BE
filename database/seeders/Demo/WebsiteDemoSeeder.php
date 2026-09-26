<?php

namespace Database\Seeders\Demo;

use App\Enums\Role;
use App\Models\GaleriAlbum;
use App\Models\Guru;
use App\Models\Pengaturan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dua album galeri publik dan data kontak demo supaya landing page lokal tidak kosong.
 * Alamat, telepon, email (.test), dan rekening di sini fiktif dan hanya untuk pengembangan.
 */
class WebsiteDemoSeeder extends Seeder
{
    private const ALBUM = [
        ['Peringatan Hari Anak Nasional 2026', 'Lomba mewarnai dan pentas lagu anak di halaman sekolah.', '2026-07-23',
            ['Lomba mewarnai Kelompok A', 'Pentas lagu Kelompok B', 'Pembagian hadiah', 'Foto bersama guru']],
        ['Karnaval Kemerdekaan RI ke-81', 'Anak-anak berkeliling kampung dengan pakaian adat dan profesi.', '2026-08-17',
            ['Barisan pakaian adat', 'Kostum profesi dokter dan polisi', 'Istirahat di balai RW', 'Kembali ke sekolah']],
    ];

    public function run(): void
    {
        foreach (self::ALBUM as [$judul, $deskripsi, $tanggal, $caption]) {
            $album = GaleriAlbum::query()->create([
                'judul' => $judul,
                'slug' => Str::slug($judul),
                'deskripsi' => $deskripsi,
                'tanggal' => $tanggal,
                'is_publik' => true,
            ]);

            foreach ($caption as $urutan => $teks) {
                $album->foto()->create([
                    'path' => GambarContoh::simpan('public', 'galeri'),
                    'caption' => $teks,
                    'urutan' => $urutan + 1,
                ]);
            }

            $album->update(['cover_path' => $album->foto()->value('path')]);
        }

        $nilai = [
            'profil.alamat' => 'Jl. Tlogosari Raya No. 45, Kec. Pedurungan, Kota Semarang',
            'profil.telepon' => '(024) 6723418',
            'profil.email' => 'tu@tkta8.test',
            'keuangan.rekening' => [['bank' => 'Bank Jateng', 'nomor' => '2012345678', 'atas_nama' => 'TK Tarbiyathul Athfal 8']],
        ];

        foreach ($nilai as $kunci => $isi) {
            Pengaturan::query()->where('kunci', $kunci)->firstOrFail()->update(['nilai' => $isi]);
        }

        Guru::query()->whereHas('user', fn ($user) => $user->where('role', Role::SuperAdmin))->update(['tampil_di_landing' => true]);
    }
}
