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
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $symbol = Setting::get('currency_symbol', '');
        $order = $this->payment->order;
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Refund sent — order '.$order->order_number)
            ->greeting('Your refund is on its way')
            ->line('We have sent '.$symbol.number_format((float) $this->payment->amount, 2)
                .' back for order '.$order->order_number.'.')
            ->line($this->payment->refund_destination
                ? 'Sent to: '.$this->payment->refund_destination
                : 'Sent by '.$this->payment->method_label.'.')
            ->line($this->payment->transaction_reference
                ? 'Reference: '.$this->payment->transaction_reference
                : 'It can take a day or two to show up on your side.')
            ->action('View your order', $frontend.'/account/orders/'.$order->order_number);
    }
}
