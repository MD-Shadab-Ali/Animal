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
        $order = $this->payment->order;
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject('Payment received — order '.$order->order_number)
            ->greeting('Thank you')
            ->line('We have received '.$symbol.number_format((float) $this->payment->amount, 2)
                .' for order '.$order->order_number.'.');

        $balance = $order->balance_due;

        $message->line($balance > 0.009
            ? 'Still outstanding: '.$symbol.number_format($balance, 2).'.'
            : 'That settles the order in full.');

        return $message->action('View your order', $frontend.'/account/orders/'.$order->order_number);
    }
}
