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
        public ?string $unsubscribeUrl = null
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = config('email-system.from.address');
        $fromName = config('email-system.from.name');
        $replyTo = config('email-system.reply_to', $fromAddress);

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            to: [new Address($this->emailLog->recipient)],
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
