<?php

namespace App\Notifications;

use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells staff a seller has an animal ready, so transport can be arranged. */
class SellerReadyForCollectionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public OrderItem $item) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->item->order;
        $seller = $this->item->seller;

        return (new MailMessage)
            ->subject('Ready for collection — '.$this->item->goat_name)
            ->greeting('A seller has an animal ready')
            ->line('**Goat:** '.$this->item->goat_name.' ('.$this->item->goat_sku.')')
            ->line('**Seller:** '.($seller?->farm_name ?? 'Unknown').' — '.($seller?->contact_phone ?? 'no phone'))
            ->line('**Pick up from:** '.trim(($seller?->address_line ?? '').' '.($seller?->area ?? '').' '.($seller?->city ?? '')))
            ->line('**For order:** '.$order?->order_number.' to '.$order?->city)
            ->when(filled($this->item->fulfilment_note),
                fn (MailMessage $mail) => $mail->line('**Seller note:** '.$this->item->fulfilment_note))
            ->action('Open the order', url('/admin/orders/'.$this->item->order_id));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title'  => 'Ready for collection',
            'body'   => $this->item->goat_name.' from '.($this->item->seller?->farm_name ?? 'a seller'),
            'url'    => '/admin/orders/'.$this->item->order_id,
            'format' => 'filament',
        ];
    }
}
