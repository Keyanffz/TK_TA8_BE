<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Tagihan;
use App\Support\Rupiah;

/**
 * Dikirim ke wali saat Kepala Sekolah membatalkan pembayaran yang salah catat. `$tagihan` dibaca setelah
 * pembatalan, jadi sisa di pesan sudah termasuk jumlah yang dibatalkan.
 */
class PembayaranDibatalkanNotification extends NotifikasiTagihan
{
    private readonly string $jumlah;

    public function __construct(Tagihan $tagihan, int $jumlah, private readonly string $alasan)
    {
        parent::__construct($tagihan);
        $this->jumlah = Rupiah::format($jumlah);
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::PembayaranDibatalkan;
    }

    protected function judul(): string
    {
        return 'Pembayaran dibatalkan';
    }

    protected function pesan(object $notifiable): string
    {
        $alasan = rtrim($this->alasan, '.');

        return "Pembayaran {$this->labelTagihan} untuk {$this->namaAnak} sebesar {$this->jumlah} dibatalkan sekolah. Alasan: {$alasan}. Sisa tagihan sekarang {$this->sisa}.";
    }
}
