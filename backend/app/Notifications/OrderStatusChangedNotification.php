<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public string $previousStatus) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = Setting::get('site_name', config('app.name'));
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $headline = match ($this->order->status) {
            'confirmed'        => 'Your order is confirmed and the goat is reserved for you.',
            'processing'       => 'We are getting your goat ready for the journey.',
            'out_for_delivery' => 'Your goat is on the way. Our driver will call before arriving.',
            'delivered'        => 'Your order has been delivered. Thank you for your custom.',
            'cancelled'        => 'Your order has been cancelled. Nothing has been charged.',
            default            => 'There is an update on your order.',
        };

        return (new MailMessage)
            ->subject('Order '.$this->order->order_number.' — '.$this->order->status_label)
            ->greeting('Hello '.$this->order->customer_name.',')
            ->line($headline)
            ->line('Status: **'.$this->order->status_label.'**')
            ->action('View your order', $frontend.'/account/orders/'.$this->order->order_number)
            ->salutation('— The '.$siteName.' team');
    }
}
