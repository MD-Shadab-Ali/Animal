<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells staff a customer says they have paid, and it needs checking. */
class PaymentSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $symbol = Setting::get('currency_symbol', '');
        $subject = $this->payment->subject();
        $noun = $subject->paymentSubjectNoun();

        $message = (new MailMessage)
            ->subject('Payment to check — '.$noun.' '.$subject->paymentReference())
            ->greeting('A customer says they have paid')
            ->line($subject->payerName().' submitted '.$symbol
                .number_format((float) $this->payment->amount, 2)
                .' for '.$noun.' '.$subject->paymentReference().'.');

        // Name the animals, or the room and the dates, so staff know what the
        // money is against without having to open it.
        foreach ($this->payment->goats() as $line) {
            $message->line('• '.$line);
        }

        return $message
            ->line('Method: '.$this->payment->method_label)
            ->line($this->payment->transaction_reference
                ? 'Reference: '.$this->payment->transaction_reference
                : 'No transaction reference was given.')
            ->line('Check it against the account, then confirm or reject it in the admin panel.')
            ->action('Open payments', url('/admin/payments'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Payment to check',
            'body' => $this->payment->subject()?->paymentReference().' — '.$this->payment->goats_summary,
            'url' => '/admin/payments',
        ];
    }
}
