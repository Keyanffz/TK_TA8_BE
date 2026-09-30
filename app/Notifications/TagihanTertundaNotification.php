<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Exceptions\PeriodeDiLuarTahunAjaranException;

/**
 * Dikirim ke Kepala Sekolah saat generate tagihan terjadwal melewati bulan berjalan karena bulan itu
 * di luar tahun ajaran aktif (biasanya tahun ajaran baru belum diaktifkan).
 */
class TagihanTertundaNotification extends NotifikasiDatabase
{
    private readonly string $bulan;

    private readonly ?string $tahunAjaran;

    public function __construct(PeriodeDiLuarTahunAjaranException $penyebab)
    {
        $this->bulan = $penyebab->periode->translatedFormat('F Y');
        $this->tahunAjaran = $penyebab->tahunAjaran;
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::TagihanTertunda;
    }

    protected function judul(): string
    {
        return "Tagihan {$this->bulan} belum dibuat";
    }

    protected function pesan(object $notifiable): string
    {
        $sebab = $this->tahunAjaran === null
            ? 'belum ada tahun ajaran aktif'
            : "bulan itu di luar tahun ajaran aktif {$this->tahunAjaran}";

        return "Tagihan bulanan {$this->bulan} belum dibuat karena {$sebab}. Aktifkan tahun ajaran yang sesuai, lalu buat tagihannya lewat generate tagihan manual.";
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, '/tahun-ajaran');
    }
}
