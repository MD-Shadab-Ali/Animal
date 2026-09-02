<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the customer their payment has been checked and accepted. */
class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $symbol = Setting::get('currency_symbol', '');
        // An order of goats, or a room booked for some nights. Everything below
        // reads the same either way; only the noun changes.
        $subject = $this->payment->subject();
        $noun = $subject->paymentSubjectNoun();
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject('Payment received — '.$noun.' '.$subject->paymentReference())
            ->greeting('Thank you')
            ->line('We have received '.$symbol.number_format((float) $this->payment->amount, 2)
                .' for '.$noun.' '.$subject->paymentReference().'.');

        $balance = $subject->balanceDue();

        $message->line($balance > 0.009
            ? 'Still outstanding: '.$symbol.number_format($balance, 2).'.'
            : 'That settles the '.$noun.' in full.');

        return $message->action(
            'View your '.$noun,
            $frontend.$subject->paymentSubjectPath(),
        );
    }
}
