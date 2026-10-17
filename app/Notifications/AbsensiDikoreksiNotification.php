<?php

namespace App\Notifications;

use App\Enums\JenisAbsensi;
use App\Enums\JenisNotifikasi;
use App\Models\Absensi;

/**
 * Dikirim ke peserta saat Kepala Sekolah mengoreksi absen masuknya atau mencatat absensi untuknya, supaya peserta
 * tahu riwayatnya berubah tanpa ia sendiri yang absen.
 */
class AbsensiDikoreksiNotification extends NotifikasiDatabase
{
    private readonly string $jenisAbsen;

    private readonly string $tanggal;

    private readonly string $bulan;

    private readonly ?string $status;

    private readonly string $catatan;

    /**
     * @param  bool  $baru  true kalau barisnya baru dibuat lewat `POST /absensi/koreksi`
     */
    public function __construct(Absensi $absensi, private readonly bool $baru)
    {
        $this->jenisAbsen = $absensi->jenis === JenisAbsensi::Masuk ? 'absen masuk' : 'absen pulang';
        $this->tanggal = $absensi->tanggal->translatedFormat('l, j F Y');
        $this->bulan = $absensi->tanggal->format('Y-m');
        $this->status = $absensi->status?->label();
        $this->catatan = rtrim((string) $absensi->catatan_koreksi, '. ');
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::AbsensiDikoreksi;
    }

    protected function judul(): string
    {
        return $this->baru ? 'Absensi dicatat Kepala Sekolah' : 'Absensi dikoreksi Kepala Sekolah';
    }

    protected function pesan(object $notifiable): string
    {
        $perubahan = match (true) {
            ! $this->baru => "mengoreksi {$this->jenisAbsen} {$this->tanggal} menjadi {$this->status}",
            $this->status !== null => "mencatat {$this->jenisAbsen} {$this->tanggal} dengan status {$this->status}",
            default => "mencatat {$this->jenisAbsen} {$this->tanggal}",
        };

        return "Kepala Sekolah {$perubahan}. Catatan: {$this->catatan}.";
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, "/absensi/riwayat?bulan={$this->bulan}");
    }
}
