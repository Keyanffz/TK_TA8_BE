<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;

class TagihanBaruNotification extends NotifikasiTagihan
{
    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::TagihanBaru;
    }

    protected function judul(): string
    {
        return 'Tagihan baru';
    }

    protected function pesan(object $notifiable): string
    {
        return "Tagihan {$this->labelTagihan} untuk {$this->namaAnak} sebesar {$this->total} jatuh tempo {$this->jatuhTempo}.";
    }
}
