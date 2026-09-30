<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tautan mengarah ke halaman FE `/mudarris/reset-password`, bukan route backend.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_url').'/mudarris/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        $menit = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Atur ulang password')
            ->greeting("Yth. {$notifiable->name},")
            ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
            ->action('Atur Ulang Password', $url)
            ->line("Tautan ini berlaku {$menit} menit. Abaikan email ini jika Anda tidak memintanya; password Anda tidak berubah.")
            ->salutation('Salam, '.config('app.name'));
    }
}
