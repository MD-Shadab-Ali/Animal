<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A stay has moved, and the guest is told.
 *
 * The mirror of OrderStatusChangedNotification, for the other half of the shop.
 * Cancelling is the one that matters most: a guest who is not told is a guest
 * who turns up.
 */
class BookingStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking, public string $previousStatus) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** What the move means, rather than the name of the state it landed in. */
    private function line(): string
    {
        return match ($this->booking->status) {
            'confirmed' => 'Your room is confirmed. We will see you on '
                .$this->booking->check_in->format('j M').'.',
            'checked_in' => 'You are checked in. Enjoy your stay.',
            'checked_out' => 'You are checked out. Thank you for staying with us.',
            'cancelled' => 'This booking has been cancelled and the room is back on sale.',
            default => 'Your stay is now '.$this->booking->status_label.'.',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your stay '.$this->booking->booking_number.' is now '.$this->booking->status_label)
            ->greeting($this->booking->status_label)
            ->line($this->line())
            ->line('**'.$this->booking->room_name.'**, '
                .$this->booking->check_in->format('j M').' to '
                .$this->booking->check_out->format('j M Y').'.')
            ->action('See your stay', url('/account/bookings/'.$this->booking->booking_number));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'booking',
            'title' => 'Your stay is now '.$this->booking->status_label,
            'body' => $this->line(),
            'url' => '/account/bookings/'.$this->booking->booking_number,
            'format' => 'filament',
        ];
    }
}
