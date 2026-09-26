<?php

namespace App\Notifications;

use App\Models\Pembayaran;
use App\Support\Rupiah;

/**
 * Dikirim ke petugas keuangan saat wali mengunggah bukti transfer.
 */
class PembayaranMasukNotification extends NotifikasiDatabase
{
    private readonly int $pembayaranId;

    private readonly string $labelTagihan;

    private readonly string $namaAnak;

    private readonly string $jumlah;

    /**
     * Butuh relasi `tagihan.jenisTagihan` dan `tagihan.murid`.
     */
    public function __construct(Pembayaran $pembayaran)
    {
        $this->pembayaranId = $pembayaran->id;
        $this->labelTagihan = $pembayaran->tagihan->label();
        $this->namaAnak = $pembayaran->tagihan->murid->nama_lengkap;
        $this->jumlah = Rupiah::format($pembayaran->jumlah);
    }

    protected function jenis(): string
    {
        return 'pembayaran_masuk';
    }

    protected function judul(): string
    {
        return 'Bukti transfer menunggu verifikasi';
    }

    protected function pesan(object $notifiable): string
    {
        return "Bukti transfer {$this->jumlah} untuk tagihan {$this->labelTagihan} ({$this->namaAnak}) menunggu verifikasi.";
    }

    protected function url(object $notifiable): string
    {
        return "/dashboard/pembayaran/{$this->pembayaranId}";
    }
}
