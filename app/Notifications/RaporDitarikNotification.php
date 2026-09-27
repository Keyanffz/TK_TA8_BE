<?php

namespace App\Notifications;

/**
 * Jenisnya tetap `rapor_revisi` karena rapor kembali berstatus revisi; yang berbeda hanya judul dan pesannya.
 */
class RaporDitarikNotification extends RaporRevisiNotification
{
    protected function judul(): string
    {
        return 'Rapor terbit ditarik untuk revisi';
    }

    protected function pesan(object $notifiable): string
    {
        return "Kepala Sekolah menarik rapor semester {$this->semester} {$this->namaMurid} ({$this->kelas}) yang sudah terbit: {$this->catatanTanpaTitik()}. Perbaiki lalu ajukan lagi.";
    }
}
