<?php

namespace App\Notifications;

use App\Models\Goat;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ListingReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Goat $goat) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = Setting::get('site_name', config('app.name'));
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        if ($this->goat->approval_status === 'approved') {
            return (new MailMessage)
                ->subject($this->goat->name.' is now live')
                ->greeting('Your listing is approved')
                ->line($this->goat->name.' ('.$this->goat->sku.') is now visible in the shop.')
                ->action('View the listing', $frontend.'/goats/'.$this->goat->slug)
                ->salutation('— The '.$siteName.' team');
        }

        return (new MailMessage)
            ->subject('Changes needed on '.$this->goat->name)
            ->greeting('Your listing needs a change')
            ->line($this->goat->name.' has not been approved yet.')
            ->line($this->goat->rejection_reason ?: 'Please review the details and submit it again.')
            ->action('Edit the listing', $frontend.'/seller/listings')
            ->salutation('— The '.$siteName.' team');
    }
}
