<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Someone wrote in through the contact form.
 *
 * Every other thing a customer does reaches staff by itself: an order, a
 * seller application, a refund request, a payment claim. A message did not.
 * It sat in the database until somebody happened to open the admin panel,
 * which is the one place nobody looks when there is nothing to look for.
 */
class ContactMessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ContactMessage $contactMessage) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('New message: '.($this->contactMessage->subject ?: 'no subject'))
            ->greeting('Someone wrote in')
            ->line('**From:** '.$this->contactMessage->name);

        // Either may be blank: the form asks for one way to reply, not both.
        if ($this->contactMessage->phone) {
            $mail->line('**Phone:** '.$this->contactMessage->phone);
        }

        if ($this->contactMessage->email) {
            $mail->line('**Email:** '.$this->contactMessage->email);
        }

        return $mail
            ->line($this->contactMessage->message)
            ->action('Open in the admin', url('/admin/contact-messages/'.$this->contactMessage->id))
            // So a reply can go straight from the inbox this landed in.
            ->replyTo(
                $this->contactMessage->email ?: config('mail.from.address'),
                $this->contactMessage->name
            );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New contact message',
            'body' => $this->contactMessage->name.': '
                .Str::limit($this->contactMessage->message, 60),
            'url' => '/admin/contact-messages/'.$this->contactMessage->id,
            'format' => 'filament',
        ];
    }
}
