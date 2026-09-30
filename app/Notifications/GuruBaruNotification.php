<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;

class GuruBaruNotification extends NotifikasiDatabase
{
    public function __construct(
        private readonly int $guruId,
        private readonly string $namaGuru,
    ) {}

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::GuruBaru;
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
        return $this->halaman($notifiable, "/guru/{$this->guruId}");
    }
}
