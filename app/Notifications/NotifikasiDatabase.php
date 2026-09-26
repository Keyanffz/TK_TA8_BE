<?php

namespace App\Notifications;

use App\Enums\JenisNotifikasi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi di dashboard dengan bentuk A7 `{ jenis, judul, pesan, url }`. `url` adalah path halaman FE.
 * Dikirim lewat queue; isi pesan disusun saat notifikasi dibuat supaya tidak bergantung pada data
 * yang mungkin sudah berubah ketika job dijalankan.
 */
abstract class NotifikasiDatabase extends Notification implements ShouldQueue
{
    use Queueable;

    abstract protected function jenis(): JenisNotifikasi;

    abstract protected function judul(): string;

    abstract protected function pesan(object $notifiable): string;

    abstract protected function url(object $notifiable): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{jenis: string, judul: string, pesan: string, url: string}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'jenis' => $this->jenis()->value,
            'judul' => $this->judul(),
            'pesan' => $this->pesan($notifiable),
            'url' => $this->url($notifiable),
        ];
    }
}
