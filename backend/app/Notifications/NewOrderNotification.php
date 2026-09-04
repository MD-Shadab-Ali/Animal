<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the farm a new order has landed, so nobody has to watch the panel. */
class NewOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $symbol = Setting::get('currency_symbol', '');

        $message = (new MailMessage)
            ->subject('New order '.$this->order->order_number.' — '.$symbol.number_format((float) $this->order->total))
            ->greeting('New order received')
            ->line('**Customer:** '.$this->order->customer_name.' ('.$this->order->customer_phone.')')
            ->line('**Deliver to:** '.$this->order->address_line.', '.$this->order->city)
            ->line('**Zone:** '.($this->order->deliveryZone?->name ?? 'Not set'))
            ->line('**Payment:** '.strtoupper($this->order->payment_method));

        foreach ($this->order->items as $item) {
            $message->line('• '.$item->goat_name.' ('.$item->goat_sku.') × '.$item->quantity);
        }

        return $message
            ->line('**Total: '.$symbol.number_format((float) $this->order->total).'**')
            ->action('Open in admin', url('/admin/orders/'.$this->order->id));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New order '.$this->order->order_number,
            'body' => $this->order->customer_name.' — '.Setting::get('currency_symbol', '')
                        .number_format((float) $this->order->total),
            'url' => '/admin/orders/'.$this->order->id,
            'format' => 'filament',
        ];
    }
}
