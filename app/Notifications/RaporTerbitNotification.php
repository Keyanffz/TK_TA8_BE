<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;

class RaporTerbitNotification extends NotifikasiRapor
{
    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::RaporTerbit;
    }

    protected function judul(): string
    {
        return 'Rapor sudah terbit';
    }

    protected function pesan(object $notifiable): string
    {
        return "Rapor semester {$this->semester} tahun ajaran {$this->tahunAjaran} untuk {$this->namaPanggilan} sudah terbit dan bisa diunduh.";
    }
}
