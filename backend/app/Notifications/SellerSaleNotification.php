<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Tells a seller that one of their goats has been bought. */
class SellerSaleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public Collection $items) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $symbol = Setting::get('currency_symbol', '');
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $earnings = round((float) $this->items->sum('seller_earning'), 2);

        $message = (new MailMessage)
            ->subject('Your goat sold — order '.$this->order->order_number)
            ->greeting('You made a sale')
            ->line('Order '.$this->order->order_number.' includes the following from you:');

        foreach ($this->items as $item) {
            $message->line('• '.$item->goat_name.' × '.$item->quantity
                .' — '.$symbol.number_format((float) $item->line_total));
        }

        return $message
            ->line('**Your earnings after commission: '.$symbol.number_format($earnings).'**')
            ->line('We will arrange collection and delivery, and pay you once the order is delivered.')
            ->action('Open your seller dashboard', $frontend.'/seller/orders');
    }
}
