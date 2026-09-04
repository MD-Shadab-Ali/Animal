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
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = Setting::get('site_name', config('app.name'));
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $headline = match ($this->order->status) {
            'confirmed' => 'Your order is confirmed and the goat is reserved for you.',
            'processing' => 'We are getting your goat ready for the journey.',
            'out_for_delivery' => 'Your goat is on the way. Our driver will call before arriving.',
            'delivered' => 'Your order has been delivered. Thank you for your custom.',
            'cancelled' => 'Your order has been cancelled. Nothing has been charged.',
            default => 'There is an update on your order.',
        };

        return (new MailMessage)
            ->subject('Order '.$this->order->order_number.' — '.$this->order->status_label)
            ->greeting('Hello '.$this->order->customer_name.',')
            ->line($headline)
            ->line('Status: **'.$this->order->status_label.'**')
            ->action('View your order', $frontend.'/account/orders/'.$this->order->order_number)
            ->salutation('— The '.$siteName.' team');
    }

    /**
     * Worded for whoever is waiting.
     *
     * buyerStatusLabel, not the raw status: on a collection order the same
     * state means "ready to collect", and telling that buyer their goat is out
     * for delivery would send them home.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'order',
            'title' => 'Order '.$this->order->order_number.' is now '.$this->order->buyerStatusLabel(),
            'body' => $this->order->statusNote ?: $this->body(),
            'url' => '/account/orders/'.$this->order->order_number,
            'format' => 'filament',
        ];
    }

    /**
     * The line under the title, worded for the status it is announcing.
     *
     * Every status used to share one body: "Tap to see where your goat has got
     * to." On the half of them that are still in motion that is fair enough. On
     * the two that have stopped it is worse than saying nothing -- a buyer whose
     * goat is standing in their yard was being invited to go and track it, and
     * one whose order was cancelled was being sent to follow a goat that is not
     * coming. The title already names the status, so this says what the buyer
     * does not know yet.
     *
     * Pickup orders branch for the reason buyerStatusLabel() exists: nobody is
     * driving anywhere, so a driver ringing ahead is not what happens next.
     */
    private function body(): string
    {
        $pickup = $this->order->isPickup();

        return match ($this->order->status) {
            'confirmed' => 'Reserved in your name. We will tell you when it is ready to move.',
            'processing' => $pickup
                ? 'We are getting your goat ready for you.'
                : 'We are getting your goat ready for the journey.',
            'out_for_delivery' => $pickup
                ? 'Penned and waiting at the farm whenever you can come for it.'
                : 'On the way now. Our driver will call before arriving.',
            'delivered' => 'Thank you for your custom.',
            'cancelled' => 'Nothing has been charged.',
            default => 'Tap to see your order.',
        };
    }
}
