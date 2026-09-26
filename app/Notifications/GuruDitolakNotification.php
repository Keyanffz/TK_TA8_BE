<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuruDitolakNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $alasan) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pendaftaran akun guru ditolak')
            ->greeting("Yth. {$notifiable->name},")
            ->line('Pendaftaran akun guru Anda ditolak Kepala Sekolah dengan alasan:')
            ->line($this->alasan)
            ->line('Jika ada pertanyaan, hubungi pihak sekolah.')
            ->salutation('Salam, '.config('app.name'));
    }
}
