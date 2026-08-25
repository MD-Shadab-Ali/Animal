<?php

namespace App\Notifications;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The link points at the Next.js storefront, not the API.
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $url = $frontend.'/reset-password?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());

        $expires = config('auth.passwords.users.expire', 60);
        $siteName = Setting::get('site_name', config('app.name'));

        return (new MailMessage)
            ->subject('Reset your '.$siteName.' password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset the password on your '.$siteName.' account.')
            ->action('Choose a new password', $url)
            ->line('This link stops working in '.$expires.' minutes.')
            ->line('If you did not ask for this, you can ignore this email — your password stays as it is.')
            ->salutation('— The '.$siteName.' team');
    }
}
