<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells staff a buyer wants their money back on a cancelled order. */
class RefundRequestedNotification extends Notification implements ShouldQueue
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
        $order = $this->payment->order;

        $message = (new MailMessage)
            ->subject('Refund requested — order '.$order->order_number)
            ->greeting('A buyer wants their money back')
            ->line($order->customer_name.' cancelled order '.$order->order_number
                .' and is asking for '.$symbol.number_format((float) $this->payment->amount, 2).' back.')
            ->line('Send it to: '.($this->payment->refund_destination ?: 'no details given'));

        if ($this->payment->refund_reason) {
            $message->line('Their reason: '.$this->payment->refund_reason);
        }

        return $message
            ->line('Send the money, then mark it refunded in the admin panel.')
            ->action('Open refunds', url('/admin/refunds'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Refund requested',
            'body'  => $this->payment->order->order_number.' — '.$this->payment->reference,
            'url'   => '/admin/refunds',
        ];
    }
}
