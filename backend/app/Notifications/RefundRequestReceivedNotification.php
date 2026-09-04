<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the buyer we have their refund request.
 *
 * RefundRequestedNotification is the other half of this and goes to staff --
 * written for somebody who has to act on it. The person who asked needs the
 * opposite: not a task, but the reassurance that asking worked and that nobody
 * is waiting on them.
 */
class RefundRequestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = Setting::currencySymbol().number_format((float) $this->payment->amount, 2);

        return (new MailMessage)
            ->subject('We have your refund request')
            ->greeting('Refund requested')
            ->line('We have your request for '.$amount.' on '.$this->payment->subjectLabel().'.')
            ->line('Somebody will check it and get the money back to you. '
                .'You do not need to do anything else.')
            ->action('See the details', url($this->payment->storefrontUrl()));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'refund',
            'title' => 'Refund requested',
            'body' => 'We have your request for '
                .Setting::currencySymbol().number_format((float) $this->payment->amount, 2)
                .'. Nothing more is needed from you.',
            'url' => $this->payment->storefrontUrl(),
            'format' => 'filament',
        ];
    }
}
