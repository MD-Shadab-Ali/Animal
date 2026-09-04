<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the buyer their refund has actually been sent. */
class RefundSentNotification extends Notification implements ShouldQueue
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
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $eta = $this->payment->arrival_eta;

        $message = (new MailMessage)
            ->subject('Refund sent — '.$noun.' '.$subject->paymentReference())
            ->greeting('Your refund is on its way')
            ->line('We have sent '.$symbol.number_format((float) $this->payment->amount, 2)
                .' back for '.$noun.' '.$subject->paymentReference().'.')
            ->line($this->payment->refund_destination
                ? 'Sent to: '.$this->payment->refund_destination
                : 'Sent by '.$this->payment->method_label.'.');

        // Say what this rail actually does. A wallet lands instantly, and
        // telling someone to wait two days for money already in their hand is
        // how you get a support call. If nobody has said, promise nothing.
        $message->line($eta
            ? 'Refunds by '.$this->payment->method_label.' usually arrive '.$eta.'.'
            : 'Please allow a little time for it to show on your side.');

        // The reference is always useful, and always available if we have one.
        if ($this->payment->transaction_reference) {
            $message->line('Reference: '.$this->payment->transaction_reference
                .' — quote this if you need to chase it.');
        }

        return $message->action(
            'View your '.$noun,
            $frontend.$subject->paymentSubjectPath(),
        );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'refund',
            'title' => 'Refund sent',
            'body' => Setting::currencySymbol().number_format((float) $this->payment->amount, 2)
                .' is on its way back to you for '.$this->payment->subjectLabel().'.',
            'url' => $this->payment->storefrontUrl(),
            'format' => 'filament',
        ];
    }
}
