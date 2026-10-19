<?php

namespace App\Enums;

/**
 * Bagian pekerjaan Kepala Sekolah yang bisa diserahkan ke guru (A2.13). Manajemen guru, manajemen izin, dan
 * pengaturan absensi sengaja tidak ada di sini karena tetap khusus Kepala Sekolah.
 */
enum ModulIzin: string
{
    case Keuangan = 'keuangan';
    case KontenWebsite = 'konten_website';
    case LaporanAbsensi = 'laporan_absensi';
    case DataMurid = 'data_murid';
    case Pengumuman = 'pengumuman';

    public function label(): string
    {
        return match ($this) {
            self::Keuangan => 'Keuangan',
            self::KontenWebsite => 'Konten Website',
            self::LaporanAbsensi => 'Laporan Absensi',
            self::DataMurid => 'Data Murid',
            self::Pengumuman => 'Pengumuman',
        };
    }

    public function deskripsi(): string
    {
        return match ($this) {
            self::Keuangan => 'Lihat: semua tagihan, pembayaran, keringanan, laporan, dan tunggakan. Kelola: keringanan, tagihan sekali bayar, ubah tagihan, catat dan verifikasi pembayaran.',
            self::KontenWebsite => 'Lihat: isi profil sekolah, landing page, banner beranda wali, dan galeri. Kelola: mengubah semuanya dan mengunggah gambar.',
            self::LaporanAbsensi => 'Lihat: rekap, absensi harian, dan riwayat beserta foto semua peserta. Kelola: koreksi status dan catat absen peserta lain.',
            self::DataMurid => 'Lihat: semua murid dan alumni. Kelola: tambah, ubah, hapus, ubah status, luluskan kelas, import, dan kartu akun wali.',
            self::Pengumuman => 'Lihat: semua pengumuman termasuk draft. Kelola: pengumuman untuk target apa pun, tampil di website, dan mengubah pengumuman siapa pun.',
        };
    }

    /**
     * Modul yang mengatur grup pengaturan, atau null untuk grup yang hanya boleh dibaca Kepala Sekolah. Grup
     * `keuangan` boleh dibaca pemegang izin keuangan, tetapi tetap hanya diubah Kepala Sekolah (rekening dan
     * aturan tagihan), lihat `untukUbahPengaturan()`.
     */
    public static function untukBacaPengaturan(string $grup): ?self
    {
        return match ($grup) {
            'profil', 'landing', 'beranda' => self::KontenWebsite,
            'keuangan' => self::Keuangan,
            default => null,
        };
    }

    public static function untukUbahPengaturan(string $grup): ?self
    {
        return $grup === 'keuangan' ? null : self::untukBacaPengaturan($grup);
    }
}
