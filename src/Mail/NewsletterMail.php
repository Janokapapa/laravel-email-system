<?php

namespace JanDev\EmailSystem\Mail;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public EmailLog $emailLog,
        public ?string $unsubscribeUrl = null,
        public ?array $senderConfig = null
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = $this->senderConfig['from_address'] ?? config('email-system.from.address');
        $fromName = $this->senderConfig['from_name'] ?? config('email-system.from.name');
        $replyTo = $this->senderConfig['reply_to'] ?? config('email-system.reply_to', $fromAddress);

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            to: [new Address($this->emailLog->recipient, $this->emailLog->recipient_name ?? '')],
            replyTo: [new Address($replyTo)],
            subject: $this->emailLog->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email-system::newsletter',
            with: [
                'emailLog' => $this->emailLog,
                'subject' => $this->emailLog->subject,
                'messageContent' => $this->emailLog->message,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
