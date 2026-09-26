<?php

namespace App\Notifications;

class TagihanBaruNotification extends NotifikasiTagihan
{
    protected function jenis(): string
    {
        return 'tagihan_baru';
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
