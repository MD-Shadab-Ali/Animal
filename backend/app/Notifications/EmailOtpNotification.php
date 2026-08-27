<?php

namespace App\Notifications;

use App\Models\EmailOtp;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The code itself, on its way to the address being proved.
 *
 * Deliberately plain: no links, nothing to click. A code the reader types back
 * in gives phishing nothing to imitate, and it cannot be spent by anyone who
 * merely saw the mail go past.
 */
class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $code,
        public string $purpose,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = Setting::get('site_name', config('app.name'));

        $subject = $this->purpose === EmailOtp::PURPOSE_REGISTER
            ? 'Your '.$site.' verification code'
            : 'Your '.$site.' password reset code';

        $reason = $this->purpose === EmailOtp::PURPOSE_REGISTER
            ? 'Enter this code to finish creating your account.'
            : 'Enter this code to choose a new password.';

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello')
            ->line($reason)
            // On its own line and nothing else, so it is easy to read off a
            // phone and hard to mistake for part of a sentence.
            ->line('**'.$this->code.'**')
            ->line('The code expires in '.EmailOtp::TTL_MINUTES.' minutes.')
            ->line($this->purpose === EmailOtp::PURPOSE_REGISTER
                ? 'If you did not sign up, you can ignore this email and no account will be created.'
                : 'If you did not ask for this, you can ignore this email — your password stays as it is.');
    }
}
