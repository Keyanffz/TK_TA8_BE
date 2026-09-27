<?php

namespace Database\Seeders\Demo;

use App\Enums\JenisAgenda;
use App\Enums\Role;
use App\Enums\StatusTagihan;
use App\Enums\TargetPengumuman;
use App\Models\Agenda;
use App\Models\Kelas;
use App\Models\Murid;
use App\Models\Pengumuman;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Pengumuman untuk setiap jenis target (termasuk murid yang menunggak SPP September dan satu draft guru)
 * serta agenda Oktober–Desember 2026.
 */
class KomunikasiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $kepalaSekolah = User::query()->where('role', Role::SuperAdmin)->firstOrFail();

        $this->pengumumanSekolah($kepalaSekolah);
        $this->pengumumanKelasDanMurid($kepalaSekolah);
        $this->agenda($kepalaSekolah);
    }

    private function pengumumanSekolah(User $kepalaSekolah): void
    {
        $this->pengumuman($kepalaSekolah, TargetPengumuman::Semua, '2026-09-01 08:00:00',
            'Pendaftaran murid baru tahun ajaran 2027/2028 dibuka',
            'Pendaftaran murid baru untuk Kelompok A dan Kelompok B dibuka mulai 1 September 2026 sampai 31 Maret 2027. Orang tua bisa mendaftar lewat halaman PPDB di website tanpa akun; wali murid yang sudah punya akun bisa mendaftarkan adik lewat menu PPDB di dashboard.',
            publik: true, pinned: true);
        $this->pengumuman($kepalaSekolah, TargetPengumuman::Semua, '2026-08-20 10:00:00',
            'Libur peringatan Maulid Nabi Muhammad SAW',
            'Sekolah libur pada hari peringatan Maulid Nabi sesuai kalender libur nasional. Kegiatan belajar dimulai lagi keesokan harinya pukul 07.30.',
            publik: true);
        $this->pengumuman($kepalaSekolah, TargetPengumuman::WaliMurid, '2026-07-20 09:00:00',
            'Pertemuan wali murid awal semester',
            'Pertemuan wali murid diadakan Sabtu, 25 Juli 2026 pukul 09.00 di aula sekolah. Kami akan menjelaskan program semester 1 dan jadwal kegiatan luar kelas.');
        $this->pengumuman($kepalaSekolah, TargetPengumuman::Guru, '2026-07-10 13:00:00',
            'Rapat penyusunan modul ajar semester 1',
            'Rapat penyusunan modul ajar diadakan Rabu, 15 Juli 2026 pukul 13.00 di ruang guru. Mohon membawa draf tema per bulan.');
    }

    private function pengumumanKelasDanMurid(User $kepalaSekolah): void
    {
        $kelasA1 = Kelas::query()->with('waliKelas.user')->where('nama', 'TK A1')->firstOrFail();
        $kelasA2 = Kelas::query()->with('waliKelas.user')->where('nama', 'TK A2')->firstOrFail();

        $this->pengumuman($kelasA1->waliKelas->user, TargetPengumuman::Kelas, '2026-09-14 12:00:00',
            'Membawa botol plastik bekas untuk kegiatan menanam',
            'Untuk kegiatan menanam hari Kamis, mohon anak membawa satu botol plastik bekas ukuran 600 ml yang sudah dicuci.')
            ->kelas()->attach($kelasA1);

        $menunggakSeptember = Murid::query()
            ->whereHas('tagihan', fn ($tagihan) => $tagihan->where('periode', '2026-09-01')->where('status', StatusTagihan::Terlambat))
            ->pluck('id');
        $this->pengumuman($kepalaSekolah, TargetPengumuman::Murid, '2026-09-15 08:00:00',
            'Pengingat pembayaran SPP September',
            'SPP bulan September sudah melewati jatuh tempo 10 September. Mohon segera melakukan pembayaran dan mengunggah bukti transfer di menu Tagihan, atau membayar tunai ke bendahara sekolah.')
            ->murid()->attach($menunggakSeptember);

        $this->pengumuman($kelasA2->waliKelas->user, TargetPengumuman::Kelas, null,
            'Rencana kunjungan ke perpustakaan daerah',
            'Kelas TK A2 berencana mengunjungi perpustakaan daerah. Jadwal dan biaya transportasi akan diumumkan setelah dikonfirmasi pihak perpustakaan.')
            ->kelas()->attach($kelasA2);
    }

    private function pengumuman(User $penulis, TargetPengumuman $target, ?string $terbit, string $judul, string $isi, bool $publik = false, bool $pinned = false): Pengumuman
    {
        return Pengumuman::query()->create([
            'judul' => $judul,
            'slug' => Str::slug($judul),
            'isi' => '<p>'.$isi.'</p>',
            'target' => $target,
            'is_publik' => $publik,
            'is_pinned' => $pinned,
            'penulis_id' => $penulis->id,
            'published_at' => $terbit,
        ]);
    }

    private function agenda(User $pembuat): void
    {
        $daftar = [
            ['Kunjungan ke Semarang Zoo', 'Berangkat dari sekolah pukul 07.30 dengan bus. Anak memakai seragam olahraga dan membawa bekal.', '2026-10-15', '2026-10-15', JenisAgenda::Kegiatan, true],
            ['Peringatan Hari Santri', 'Anak-anak memakai baju muslim dan sarung atau gamis. Ada lomba hafalan doa harian.', '2026-10-22', '2026-10-22', JenisAgenda::Kegiatan, true],
            ['Rapat evaluasi bulanan guru', 'Evaluasi kegiatan bulan Oktober dan persiapan penilaian semester.', '2026-10-31', '2026-10-31', JenisAgenda::Rapat, false],
            ['Peringatan Hari Guru', 'Upacara singkat dan pentas lagu dari anak-anak untuk para guru.', '2026-11-25', '2026-11-25', JenisAgenda::Kegiatan, true],
            ['Pembagian rapor semester 1', 'Rapor dibagikan kepada wali murid di kelas masing-masing pukul 08.00–11.00.', '2026-12-19', '2026-12-19', JenisAgenda::Kegiatan, true],
            ['Libur semester 1', 'Kegiatan belajar dimulai lagi pada awal semester 2.', '2026-12-21', '2027-01-02', JenisAgenda::Libur, true],
        ];

        foreach ($daftar as [$judul, $deskripsi, $mulai, $selesai, $jenis, $publik]) {
            Agenda::query()->create([
                'judul' => $judul,
                'deskripsi' => $deskripsi,
                'tanggal_mulai' => $mulai,
                'tanggal_selesai' => $selesai,
                'jenis' => $jenis,
                'is_publik' => $publik,
                'dibuat_oleh' => $pembuat->id,
            ]);
        }
    }
}
