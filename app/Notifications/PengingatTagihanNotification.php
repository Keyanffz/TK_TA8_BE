<?php

namespace App\Notifications;

use App\Models\Tagihan;

class PengingatTagihanNotification extends NotifikasiTagihan
{
    public function __construct(Tagihan $tagihan, private readonly int $sisaHari)
    {
        parent::__construct($tagihan);
    }

    protected function jenis(): string
    {
        return 'pengingat_tagihan';
    }

    protected function judul(): string
    {
        return 'Pengingat jatuh tempo tagihan';
    }

    protected function pesan(object $notifiable): string
    {
        return "Tagihan {$this->labelTagihan} untuk {$this->namaAnak} sebesar {$this->total} jatuh tempo {$this->sisaHari} hari lagi ({$this->jatuhTempo}).";
    }
}
