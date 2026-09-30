<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Pendaftaran;

/**
 * Dikirim ke Kepala Sekolah saat wali murid mengirim pendaftaran PPDB.
 */
class PendaftaranBaruNotification extends NotifikasiDatabase
{
    private readonly int $pendaftaranId;

    private readonly string $kode;

    private readonly string $namaAnak;

    private readonly string $tingkat;

    public function __construct(Pendaftaran $pendaftaran)
    {
        $this->pendaftaranId = $pendaftaran->id;
        $this->kode = $pendaftaran->kode;
        $this->namaAnak = $pendaftaran->nama_lengkap;
        $this->tingkat = $pendaftaran->tingkat_tujuan->value;
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::PendaftaranBaru;
    }

    protected function judul(): string
    {
        return 'Pendaftar PPDB baru';
    }

    protected function pesan(object $notifiable): string
    {
        return "{$this->namaAnak} didaftarkan ke Kelompok {$this->tingkat} ({$this->kode}). Periksa dokumennya.";
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, "/ppdb/{$this->pendaftaranId}");
    }
}
