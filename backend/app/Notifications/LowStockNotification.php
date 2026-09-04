<?php

namespace App\Notifications;

use App\Models\Goat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Warns the farm when an order pushes a goat to or below the low-stock threshold. */
class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Goat $goat) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $soldOut = $this->goat->stock <= 0;

        return (new MailMessage)
            ->subject(($soldOut ? 'Sold out: ' : 'Low stock: ').$this->goat->name)
            ->greeting($soldOut ? 'A goat has sold out' : 'A goat is running low')
            ->line($this->goat->name.' ('.$this->goat->sku.')')
            ->line('Remaining stock: **'.$this->goat->stock.'**')
            ->line($soldOut
                ? 'It has been marked sold and removed from the shop.'
                : 'It is still listed, but not for much longer.')
            ->action('Open in admin', url('/admin/goats/'.$this->goat->id.'/edit'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->goat->stock <= 0 ? 'Sold out' : 'Low stock',
            'body' => $this->goat->name.' — '.$this->goat->stock.' left',
            'url' => '/admin/goats/'.$this->goat->id.'/edit',
            'format' => 'filament',
        ];
    }
}
