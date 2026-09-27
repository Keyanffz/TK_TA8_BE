<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use App\Enums\Role;
use App\Models\User;

/**
 * Dikirim ke Kepala Sekolah dan wali lain yang sudah tertaut ke anak yang sama saat wali menambahkan anak ke
 * akunnya, supaya penautan oleh orang yang tidak dikenal (misalnya NIS dan tanggal lahir diketahui orang lain)
 * cepat ketahuan.
 */
class AnakTertautNotification extends NotifikasiDatabase
{
    public function __construct(
        private readonly int $muridId,
        private readonly string $namaAnak,
        private readonly string $namaWali,
        private readonly string $hubungan,
    ) {}

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::AnakTertaut;
    }

    protected function judul(): string
    {
        return 'Wali murid baru tertaut';
    }

    protected function pesan(object $notifiable): string
    {
        return "{$this->namaWali} ({$this->hubungan}) menautkan akunnya ke {$this->namaAnak}.";
    }

    protected function url(object $notifiable): string
    {
        return $notifiable instanceof User && $notifiable->role === Role::SuperAdmin
            ? "/dashboard/murid/{$this->muridId}"
            : '/dashboard/anak';
    }
}
