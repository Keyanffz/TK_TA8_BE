<?php

namespace App\Notifications;

class TagihanTerlambatNotification extends NotifikasiTagihan
{
    protected function jenis(): string
    {
        return 'tagihan_terlambat';
    }

    protected function judul(): string
    {
        return 'Tagihan lewat jatuh tempo';
    }

    protected function pesan(object $notifiable): string
    {
        return "Tagihan {$this->labelTagihan} untuk {$this->namaAnak} sebesar {$this->total} sudah lewat jatuh tempo {$this->jatuhTempo}. Mohon segera dibayar.";
    }
}
