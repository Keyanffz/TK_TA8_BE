<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuruDisetujuiNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('Akun guru Anda sudah disetujui')
            ->greeting("Yth. {$notifiable->name},")
            ->line('Kepala Sekolah sudah menyetujui pendaftaran akun guru Anda. Silakan masuk dengan email dan password yang Anda buat saat mendaftar.')
            ->action('Masuk ke Dashboard', config('app.frontend_url').'/login')
            ->salutation('Salam, '.config('app.name'));
    }
}
