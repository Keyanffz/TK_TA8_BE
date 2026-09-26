<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;

class PembayaranDiterimaNotification extends NotifikasiTagihan
{
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
        return "Pembayaran {$this->labelTagihan} untuk {$this->namaAnak} sebesar {$this->total} sudah diterima. Kwitansi bisa diunduh dari halaman tagihan.";
    }
}
