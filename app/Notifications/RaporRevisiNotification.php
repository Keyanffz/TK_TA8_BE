<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Models\Rapor;

class RaporRevisiNotification extends NotifikasiRapor
{
    public function __construct(Rapor $rapor, private readonly string $catatan)
    {
        parent::__construct($rapor);
    }

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::RaporRevisi;
    }

    protected function judul(): string
    {
        return 'Rapor perlu direvisi';
    }

    protected function pesan(object $notifiable): string
    {
        return "Kepala Sekolah meminta revisi rapor semester {$this->semester} {$this->namaMurid} ({$this->kelas}): {$this->catatanTanpaTitik()}.";
    }

    protected function catatanTanpaTitik(): string
    {
        return rtrim($this->catatan, '. ');
    }
}
