<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Tells a seller an order carrying their goats has been called off.
 *
 * Buyers can cancel right up to the handover, which means this can land while
 * the animal is already penned and waiting. Finding out by noticing a line has
 * quietly turned grey is not good enough.
 */
class SellerOrderCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public Collection $items) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject('Order cancelled — '.$this->order->order_number)
            ->greeting('An order has been cancelled')
            ->line('Order '.$this->order->order_number.' was cancelled, so please stop work on:');

        foreach ($this->items as $item) {
            $message->line('• '.$item->goat_name.' × '.$item->quantity);
        }

        return $message
            ->line($this->order->status_label === 'Cancelled' && $this->order->cancelled_at
                ? 'Cancelled on '.$this->order->cancelled_at->format('d M Y, g:i a').'.'
                : 'It is no longer going ahead.')
            ->line('Your goats have gone back on sale automatically. Nothing is owed to you for this order.')
            ->action('Your sales', $frontend.'/seller/orders');
    }
}
