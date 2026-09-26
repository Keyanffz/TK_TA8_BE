<?php

namespace App\Notifications;

class PembayaranDiterimaNotification extends NotifikasiTagihan
{
    protected function jenis(): string
    {
        return 'pembayaran_diterima';
    }

    protected function judul(): string
    {
        return 'Pembayaran diterima';
    }

    protected function pesan(object $notifiable): string
    {
        return "Pembayaran {$this->labelTagihan} untuk {$this->namaAnak} sebesar {$this->total} sudah diterima. Kwitansi bisa diunduh dari halaman tagihan.";
    }
}
