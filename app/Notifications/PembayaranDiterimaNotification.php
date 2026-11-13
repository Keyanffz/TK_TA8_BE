<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Tagihan;
use App\Support\Rupiah;

/**
 * Dikirim ke wali setiap pembayaran tunai dicatat. `$sisa` adalah sisa tagihan setelah pembayaran ini.
 */
class PembayaranDiterimaNotification extends NotifikasiTagihan
{
    private readonly string $jumlah;

    private readonly ?string $sisaSetelahBayar;

    public function __construct(Tagihan $tagihan, int $jumlah, int $sisa)
    {
        parent::__construct($tagihan);
        $this->jumlah = Rupiah::format($jumlah);
        $this->sisaSetelahBayar = $sisa === 0 ? null : Rupiah::format($sisa);
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::PembayaranDiterima;
    }

    protected function judul(): string
    {
        return 'Pembayaran diterima';
    }

    protected function pesan(object $notifiable): string
    {
        $keadaan = $this->sisaSetelahBayar === null ? 'Tagihan sudah lunas.' : "Sisa tagihan {$this->sisaSetelahBayar}.";

        return "Pembayaran {$this->labelTagihan} untuk {$this->namaAnak} sebesar {$this->jumlah} sudah diterima. {$keadaan} Kwitansi bisa diunduh dari halaman tagihan.";
    }
}
