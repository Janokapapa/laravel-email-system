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
        $replyTo = $this->senderConfig['reply_to'] ?? config('email-system.reply_to');

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            to: [new Address($this->emailLog->recipient, $this->emailLog->recipient_name ?? '')],
            replyTo: $replyTo ? [new Address($replyTo)] : [],
            subject: $this->emailLog->subject,
        );
    }

    public function content(): Content
    {
        $contentType = $this->emailLog->content_type ?? 'html';

        if ($contentType === 'text') {
            return new Content(
                text: 'email-system::newsletter-text',
                with: [
                    'messageContent' => $this->emailLog->message,
                    'unsubscribeUrl' => $this->unsubscribeUrl,
                ],
            );
        }

        if ($contentType === 'both') {
            // Multipart/alternative: HTML part + plain text fallback.
            // strip_tags() is applied to the raw message body (not the layout-rendered HTML)
            // to avoid including layout chrome (app name, footer) in the text part.
            return new Content(
                view: 'email-system::newsletter',
                text: 'email-system::newsletter-text-multipart',
                with: [
                    'emailLog'       => $this->emailLog,
                    'subject'        => $this->emailLog->subject,
                    'messageContent' => $this->emailLog->message,
                    'textContent'    => strip_tags((string) $this->emailLog->message),
                    'unsubscribeUrl' => $this->unsubscribeUrl,
                    'trackOpens'     => $this->senderConfig['track_opens'] ?? false,
                ],
            );
        }

        return new Content(
            view: 'email-system::newsletter',
            with: [
                'emailLog'       => $this->emailLog,
                'subject'        => $this->emailLog->subject,
                'messageContent' => $this->emailLog->message,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'trackOpens'     => $this->senderConfig['track_opens'] ?? false,
            ],
        );
    }
}
