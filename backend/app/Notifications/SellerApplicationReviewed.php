<?php

namespace App\Notifications;

use App\Models\Seller;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SellerApplicationReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Seller $seller) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = Setting::get('site_name', config('app.name'));
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        if ($this->seller->status === 'approved') {
            return (new MailMessage)
                ->subject('You can start selling on '.$siteName)
                ->greeting('Good news, '.$notifiable->name.'.')
                ->line($this->seller->farm_name.' has been approved.')
                ->line('You can now create listings. Each one is checked by our team before it appears in the shop.')
                ->line('Commission on each sale is '.$this->seller->effective_commission_rate.'%.')
                ->action('Open your seller dashboard', $frontend.'/seller')
                ->salutation('— The '.$siteName.' team');
        }

        if ($this->seller->status === 'suspended') {
            return (new MailMessage)
                ->subject('Your '.$siteName.' seller account is suspended')
                ->greeting('Hello '.$notifiable->name.',')
                ->line('Your listings have been taken off the shop while we look into something.')
                ->line($this->seller->review_note ?: 'Please get in touch so we can sort this out.')
                ->salutation('— The '.$siteName.' team');
        }

        return (new MailMessage)
            ->subject('About your '.$siteName.' seller application')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We are not able to approve '.$this->seller->farm_name.' at the moment.')
            ->line($this->seller->review_note ?: 'Please contact us if you would like to know more.')
            ->salutation('— The '.$siteName.' team');
    }
}
