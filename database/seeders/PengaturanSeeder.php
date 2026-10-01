<?php

namespace Database\Seeders;

use App\Models\Pengaturan;
use App\Support\AturanAbsensi;
use Illuminate\Database\Seeder;

/**
 * Nilai awal kunci pengaturan A4. Data identitas yang hanya diketahui sekolah (NPSN, alamat,
 * kontak, sejarah, sambutan, fasilitas) dibiarkan kosong untuk diisi Kepala Sekolah lewat CMS,
 * supaya landing page tidak menampilkan data karangan. Kunci yang sudah ada tidak ditimpa.
 */
class PengaturanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->nilaiAwal() as $kunci => $nilai) {
            Pengaturan::query()->firstOrCreate(
                ['kunci' => $kunci],
                ['nilai' => $nilai, 'grup' => strstr($kunci, '.', true)],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function nilaiAwal(): array
    {
        return [
            'profil.nama_sekolah' => 'TK Tarbiyathul Athfal 8',
            'profil.npsn' => '',
            'profil.alamat' => '',
            'profil.telepon' => '',
            'profil.email' => '',
            'profil.maps_embed_url' => '',
            'profil.logo' => null,
            'profil.visi' => 'Anak tumbuh beriman dan berakhlak baik, serta siap melanjutkan ke sekolah dasar.',
            'profil.misi' => [
                'Membiasakan doa, salat, dan hafalan surat pendek dalam kegiatan harian.',
                'Mengembangkan kemampuan anak lewat bermain sesuai Kurikulum Merdeka PAUD.',
                'Menjalin komunikasi rutin dengan orang tua tentang perkembangan anak.',
            ],
            'profil.sejarah' => '',
            'profil.sambutan_kepsek' => '',
            'landing.hero' => [
                'judul' => 'TK Tarbiyathul Athfal 8',
                'subjudul' => 'Taman kanak-kanak untuk anak usia 4–6 tahun.',
                'gambar' => null,
                'cta_teks' => 'Lihat Info PPDB',
            ],
            'landing.program' => [
                [
                    'judul' => 'Kelompok A',
                    'deskripsi' => 'Untuk anak usia 4–5 tahun. Fokus pada pembiasaan, bermain bersama, dan mengenal lingkungan sekitar.',
                    'ikon' => 'blocks',
                ],
                [
                    'judul' => 'Kelompok B',
                    'deskripsi' => 'Untuk anak usia 5–6 tahun. Persiapan masuk sekolah dasar lewat kegiatan membaca, menulis, dan berhitung sambil bermain.',
                    'ikon' => 'book-open',
                ],
            ],
            'landing.fasilitas' => [],
            'landing.keunggulan' => [],
            'keuangan.rekening' => [],
            'keuangan.tanggal_jatuh_tempo' => 10,
            'keuangan.hari_pengingat' => 3,
            'ppdb.dibuka' => false,
            'ppdb.tanggal_buka' => null,
            'ppdb.tanggal_tutup' => null,
            'ppdb.tahun_ajaran_id' => null,
            'ppdb.kuota' => 0,
            'ppdb.info' => '',
            'beranda.info_wali' => [
                'aktif' => false,
                'judul' => null,
                'isi' => null,
                'nada' => 'info',
                'berlaku_sampai' => null,
            ],
            ...AturanAbsensi::BAWAAN,
            AturanAbsensi::KUNCI_TANGGAL_MULAI => today()->toDateString(),
        ];
    }
}
