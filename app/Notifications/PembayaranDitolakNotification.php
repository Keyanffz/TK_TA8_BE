<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Tagihan;

class PembayaranDitolakNotification extends NotifikasiTagihan
{
    public function __construct(Tagihan $tagihan, private readonly string $alasan)
    {
        parent::__construct($tagihan);
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::PembayaranDitolak;
    }

    protected function judul(): string
    {
        return 'Bukti transfer ditolak';
    }

    protected function pesan(object $notifiable): string
    {
        $alasan = rtrim($this->alasan, '. ');

        return "Bukti transfer untuk tagihan {$this->labelTagihan} ({$this->namaAnak}) ditolak: {$alasan}. Silakan unggah ulang bukti yang benar.";
    }
}
