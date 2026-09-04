<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a guest their room is held.
 *
 * Booking a room said nothing to anybody at all: the nights were taken, the
 * money was asked for, and the only trace was a page the guest had to think to
 * go back to. An order has told its buyer since the beginning; a stay should
 * not be the quieter half of the same shop.
 */
class BookingPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function dates(): string
    {
        return $this->booking->check_in->format('j M').' to '
            .$this->booking->check_out->format('j M Y');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your stay '.$this->booking->booking_number.' is booked')
            ->greeting('Room held')
            ->line('**'.$this->booking->room_name.'**, '.$this->dates().'.')
            ->line('We have put your name on it. Anything still to pay is on the booking page.')
            ->action('See your stay', url('/account/bookings/'.$this->booking->booking_number));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'booking',
            'title' => $this->booking->room_name.' is held for you',
            'body' => $this->dates().' · '.$this->booking->booking_number,
            'url' => '/account/bookings/'.$this->booking->booking_number,
            'format' => 'filament',
        ];
    }
}
