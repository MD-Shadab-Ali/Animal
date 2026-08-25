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
        $order = $this->payment->order;

        $message = (new MailMessage)
            ->subject('Payment to check — order '.$order->order_number)
            ->greeting('A customer says they have paid')
            ->line($order->customer_name.' submitted '.$symbol
                .number_format((float) $this->payment->amount, 2)
                .' for order '.$order->order_number.'.');

        // Name the animals, so staff know what the money is against without
        // having to open the order.
        foreach ($this->payment->goats() as $goat) {
            $message->line('• '.$goat);
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
            'body'  => $this->payment->order->order_number.' — '.$this->payment->goats_summary,
            'url'   => '/admin/payments',
        ];
    }
}
