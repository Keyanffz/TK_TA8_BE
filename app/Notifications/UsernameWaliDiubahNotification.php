<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;

/**
 * Dikirim ke akun wali yang username-nya ikut berganti karena Kepala Sekolah mengubah NIS anak. Sesi yang sedang
 * berjalan tetap berlaku, jadi wali baru perlu username baru saat masuk lagi.
 */
class UsernameWaliDiubahNotification extends NotifikasiDatabase
{
    public function __construct(
        private readonly string $namaAnak,
        private readonly string $nisBaru,
    ) {}

    protected function jenis(): JenisNotifikasi
    {
        return JenisNotifikasi::UsernameDiubah;
    }

    protected function judul(): string
    {
        return 'Username akun Anda berubah';
    }

    protected function pesan(object $notifiable): string
    {
        return "NIS {$this->namaAnak} diubah sekolah menjadi {$this->nisBaru}. Mulai sekarang masuk dengan username {$this->nisBaru}; password Anda tidak berubah.";
    }

    protected function url(object $notifiable): string
    {
        return $this->halaman($notifiable, '/profil');
    }
}
