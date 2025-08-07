<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $title,
        public string $notificationMessage
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->user->email,
            subject: $this->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: [
                'user' => $this->user,
                'title' => $this->title,
                'notificationMessage' => $this->notificationMessage,
            ]
        );
    }
}