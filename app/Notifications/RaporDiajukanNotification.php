<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Rapor;

class RaporDiajukanNotification extends NotifikasiRapor
{
    private readonly string $namaGuru;

    /**
     * Butuh juga relasi `pembuat.user`.
     */
    public function __construct(Rapor $rapor)
    {
        parent::__construct($rapor);
        $this->namaGuru = $rapor->pembuat->user->name;
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::RaporDiajukan;
    }

    protected function judul(): string
    {
        return 'Rapor menunggu review';
    }

    protected function pesan(object $notifiable): string
    {
        return "Rapor semester {$this->semester} {$this->namaMurid} ({$this->kelas}) diajukan {$this->namaGuru} dan menunggu review Anda.";
    }
}
