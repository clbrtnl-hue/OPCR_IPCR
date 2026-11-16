<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PmsNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $headline,
        public ?string $detail = null,
        public ?string $actionUrl = null,
        public ?string $actorName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->headline,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.pms-notification',
            text: 'mail.pms-notification-text',
        );
    }
}
