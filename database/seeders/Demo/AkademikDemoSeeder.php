<?php

namespace Database\Seeders\Demo;

use App\Enums\Role;
use App\Enums\StatusRapor;
use App\Models\ElemenPenilaian;
use App\Models\KegiatanFoto;
use App\Models\KegiatanKelas;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Rapor;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Kegiatan kelas berfoto untuk semua kelas, rapor semester 1 TK B1 dalam berbagai status,
 * dan beberapa draft rapor TK A1.
 */
class AkademikDemoSeeder extends Seeder
{
    private const KEGIATAN = [
        ['Tanaman', 'Menanam biji kacang hijau', 'Anak-anak menanam biji kacang hijau di gelas plastik bekas dan mengamati pertumbuhannya setiap pagi.'],
        ['Aku Hebat', 'Praktik wudu dan salat duha', 'Anak-anak berlatih urutan wudu dengan pendampingan guru, lalu salat duha berjamaah di musala sekolah.'],
        ['Lingkunganku', 'Membuat kolase daun kering', 'Anak-anak mengumpulkan daun kering di halaman dan menempelkannya menjadi gambar binatang.'],
        ['Keluargaku', 'Bercerita tentang anggota keluarga', 'Setiap anak menunjukkan foto keluarga dan menyebutkan nama serta pekerjaan orang tuanya.'],
        ['Kendaraan', 'Bermain peran di terminal mini', 'Anak-anak bergantian menjadi sopir, kondektur, dan penumpang untuk belajar antre dan membayar tiket.'],
        ['Aku Hebat', 'Senam pagi dan permainan estafet', 'Senam pagi bersama dilanjutkan estafet bola dalam kelompok kecil untuk melatih kerja sama.'],
    ];

    /** Deskripsi naratif per kode elemen; `%s` diganti nama panggilan murid. */
    private const DESKRIPSI_ELEMEN = [
        'NAB' => '%s sudah hafal doa sebelum makan dan doa keluar rumah. Saat salat duha berjamaah, %1$s mengikuti gerakan dengan tertib dan mau mengingatkan teman yang bercanda.',
        'JD' => '%s mulai berani bercerita di depan kelas tentang kegiatan di rumah. %1$s sudah bisa memakai sepatu sendiri, tetapi masih perlu dibantu saat mengancingkan baju.',
        'LITERASI_STEAM' => '%s mengenal semua huruf vokal dan bisa menyebut benda yang diawali huruf itu. Saat menanam kacang hijau, %1$s rajin menyiram dan menghitung daun yang tumbuh sampai sepuluh.',
    ];

    public function run(): void
    {
        $kelas = Kelas::query()->orderBy('nama')->get();

        foreach ($kelas as $urutan => $satuKelas) {
            $this->buatKegiatan($satuKelas, $urutan);
        }

        $elemen = ElemenPenilaian::query()->where('is_aktif', true)->orderBy('urutan')->get();
        $kepalaSekolah = User::query()->where('role', Role::SuperAdmin)->firstOrFail();

        $kelasB1 = Kelas::query()->where('nama', 'TK B1')->firstOrFail();
        $kelasA1 = Kelas::query()->where('nama', 'TK A1')->firstOrFail();

        foreach ($kelasB1->murid()->orderBy('murid.id')->get() as $urutan => $murid) {
            $status = match (true) {
                $urutan < 4 => StatusRapor::Terbit,
                $urutan < 6 => StatusRapor::Revisi,
                $urutan < 10 => StatusRapor::Diajukan,
                default => StatusRapor::Draft,
            };
            $this->buatRapor($murid, $kelasB1, $elemen, $status, $kepalaSekolah, isiLengkap: $urutan < 13);
        }

        foreach ($kelasA1->murid()->orderBy('murid.id')->limit(3)->get() as $murid) {
            $this->buatRapor($murid, $kelasA1, $elemen, StatusRapor::Draft, $kepalaSekolah, isiLengkap: false);
        }
    }

    private function buatKegiatan(Kelas $kelas, int $urutanKelas): void
    {
        for ($i = 0; $i < 3; $i++) {
            [$tema, $judul, $deskripsi] = self::KEGIATAN[($urutanKelas + $i * 2) % count(self::KEGIATAN)];

            $kegiatan = KegiatanKelas::query()->create([
                'kelas_id' => $kelas->id,
                'guru_id' => $kelas->wali_kelas_id,
                'tanggal' => Carbon::parse('2026-08-05')->addDays($i * 14 + $urutanKelas)->toDateString(),
                'tema' => $tema,
                'judul' => $judul,
                'deskripsi' => $deskripsi,
            ]);

            foreach ([1, 2] as $urutanFoto) {
                KegiatanFoto::query()->create([
                    'kegiatan_kelas_id' => $kegiatan->id,
                    'path' => GambarContoh::simpan('local', 'kegiatan'),
                    'caption' => $urutanFoto === 1 ? $judul : null,
                    'urutan' => $urutanFoto,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, ElemenPenilaian>  $elemen
     */
    private function buatRapor(Murid $murid, Kelas $kelas, Collection $elemen, StatusRapor $status, User $kepalaSekolah, bool $isiLengkap): void
    {
        $sudahDiajukan = in_array($status, [StatusRapor::Diajukan, StatusRapor::Revisi, StatusRapor::Terbit], true);
        $nama = $murid->nama_panggilan;

        $rapor = Rapor::query()->create([
            'murid_id' => $murid->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $kelas->tahun_ajaran_id,
            'semester' => 1,
            'tinggi_badan' => $isiLengkap ? fake()->randomFloat(1, 105, 118) : null,
            'berat_badan' => $isiLengkap ? fake()->randomFloat(1, 16, 22) : null,
            'catatan_guru' => $isiLengkap
                ? "{$nama} anak yang ceria dan senang membantu teman. Di rumah, mohon ajak {$nama} membaca buku cerita bergambar 10 menit sebelum tidur."
                : null,
            'status' => $status,
            'catatan_revisi' => $status === StatusRapor::Revisi
                ? 'Deskripsi Jati Diri masih umum. Tambahkan contoh perilaku yang Ibu amati di kelas.'
                : null,
            'dibuat_oleh' => $kelas->wali_kelas_id,
            'diajukan_at' => $sudahDiajukan ? '2026-09-18 14:00:00' : null,
            'disetujui_oleh' => $status === StatusRapor::Terbit ? $kepalaSekolah->id : null,
            'terbit_at' => $status === StatusRapor::Terbit ? '2026-09-22 09:00:00' : null,
        ]);

        foreach ($elemen as $satuElemen) {
            $templat = self::DESKRIPSI_ELEMEN[$satuElemen->kode] ?? null;

            $rapor->detail()->create([
                'elemen_penilaian_id' => $satuElemen->id,
                'deskripsi' => $isiLengkap && $templat !== null ? sprintf($templat, $nama) : null,
            ]);
        }
    }
}
