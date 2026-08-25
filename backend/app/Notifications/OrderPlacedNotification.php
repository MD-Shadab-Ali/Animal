<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = Setting::get('site_name', config('app.name'));
        $symbol = Setting::get('currency_symbol', '');
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject('Order '.$this->order->order_number.' received')
            ->greeting('Thank you, '.$this->order->customer_name.'.')
            ->line('We have your order and will call '.$this->order->customer_phone.' to confirm before delivery.');

        foreach ($this->order->items as $item) {
            $message->line('• '.$item->goat_name.' × '.$item->quantity
                .' — '.$symbol.number_format((float) $item->line_total));
        }

        $message
            ->line('**Total to pay on delivery: '.$symbol.number_format((float) $this->order->total).'**')
            ->line('Delivering to: '.$this->order->address_line.', '.$this->order->city)
            ->action('Track your order', $frontend.'/account/orders/'.$this->order->order_number)
            ->line('Payment is cash on delivery — please have the amount ready.')
            ->salutation('— The '.$siteName.' team');

        return $message;
    }
}
