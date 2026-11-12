<?php

namespace App\Notifications;

use App\Enums\JenisAbsensi;
use App\Enums\JenisNotifikasi;
use App\Models\AksesAbsenSusulan;

/**
 * Dikirim ke peserta saat Kepala Sekolah memberi akses absen susulan, supaya peserta tahu ia bisa absen sendiri
 * dari halaman Absensi sebelum aksesnya kedaluwarsa.
 */
class AbsensiSusulanDiberikanNotification extends NotifikasiDatabase
{
    private readonly string $jenisAbsen;

    private readonly string $tanggal;

    private readonly string $berlakuSampai;

    private readonly string $catatan;

    public function __construct(AksesAbsenSusulan $akses)
    {
        $this->jenisAbsen = $akses->jenis === JenisAbsensi::Masuk ? 'absen masuk' : 'absen pulang';
        $this->tanggal = $akses->tanggal->translatedFormat('l, j F Y');
        $this->berlakuSampai = $akses->berlaku_sampai->translatedFormat('j F Y').' pukul '.$akses->berlaku_sampai->format('H:i');
        $this->catatan = rtrim($akses->catatan, '. ');
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::AbsensiSusulanDiberikan;
    }

    protected function judul(): string
    {
        return 'Akses absen susulan dari Kepala Sekolah';
    }

    protected function pesan(object $notifiable): string
    {
        return "Anda bisa {$this->jenisAbsen} untuk {$this->tanggal} dengan swafoto, berlaku sampai {$this->berlakuSampai}. Catatan: {$this->catatan}.";
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, '/absensi');
    }
}
