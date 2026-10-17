<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Absensi;

/**
 * Dikirim ke peserta setelah absen sendiri berhasil, sebagai bukti yang bisa dibuka lagi kalau ada selisih dengan
 * catatan sekolah.
 */
class AbsensiTercatatNotification extends NotifikasiDatabase
{
    private readonly string $namaAbsen;

    private readonly string $tanggal;

    private readonly string $jam;

    private readonly ?string $status;

    private readonly ?string $keterangan;

    public function __construct(Absensi $absensi)
    {
        $this->namaAbsen = $absensi->namaAbsen();
        $this->tanggal = $absensi->tanggal->translatedFormat('l, j F Y');
        $this->jam = (string) $absensi->waktu?->format('H:i');
        $this->status = $absensi->status?->perluAbsenPulang() ? $absensi->status->label() : null;
        $this->keterangan = $absensi->keterangan;
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::AbsensiTercatat;
    }

    protected function judul(): string
    {
        return "{$this->namaAbsen} tercatat";
    }

    protected function pesan(object $notifiable): string
    {
        $status = $this->status === null ? '' : " dengan status {$this->status}";
        $keterangan = $this->keterangan === null ? '' : ' Keterangan: '.rtrim($this->keterangan, '. ').'.';

        return "{$this->namaAbsen} {$this->tanggal} pukul {$this->jam} tercatat{$status}.{$keterangan}";
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, '/absensi/riwayat');
    }
}
