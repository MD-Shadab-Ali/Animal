<?php

namespace App\Notifications;

use App\Models\Seller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewSellerApplicationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Seller $seller) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New seller application — '.$this->seller->farm_name)
            ->greeting('Someone wants to sell')
            ->line('**Farm:** '.$this->seller->farm_name)
            ->line('**Contact:** '.$this->seller->contact_phone)
            ->line('**Location:** '.trim($this->seller->area.' '.$this->seller->city))
            ->action('Review the application', url('/admin/sellers/'.$this->seller->id.'/edit'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title'  => 'New seller application',
            'body'   => $this->seller->farm_name.' — '.$this->seller->city,
            'url'    => '/admin/sellers/'.$this->seller->id.'/edit',
            'format' => 'filament',
        ];
    }
}
