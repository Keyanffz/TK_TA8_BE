<?php

namespace App\Notifications;

class GuruBaruNotification extends NotifikasiDatabase
{
    public function __construct(
        private readonly int $guruId,
        private readonly string $namaGuru,
    ) {}

    protected function jenis(): string
    {
        return 'guru_baru';
    }

    protected function judul(): string
    {
        return 'Pendaftaran guru baru';
    }

    protected function pesan(object $notifiable): string
    {
        return "{$this->namaGuru} mendaftar sebagai guru dan menunggu persetujuan Anda.";
    }

    protected function url(object $notifiable): string
    {
        return "/dashboard/guru/{$this->guruId}";
    }
}
