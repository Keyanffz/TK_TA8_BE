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
        $catatan = rtrim($this->catatan, '. ');

        return "Kepala Sekolah meminta revisi rapor semester {$this->semester} {$this->namaMurid} ({$this->kelas}): {$catatan}.";
    }
}
