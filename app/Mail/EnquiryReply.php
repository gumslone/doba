<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The hotel's answer to a contact-form enquiry, written in the panel (§12).
 *
 * The body is the hotelier's own words, sent as typed: no template
 * greeting in a language the hotelier may not read, no footer they did
 * not write. Reply-To is the hotel's inbox so the guest's next message
 * lands where the first one did.
 */
class EnquiryReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Enquiry $enquiry,
        public string $subjectLine,
        public string $body,
        public string $hotelName,
        public ?string $hotelEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->hotelEmail !== null && $this->hotelEmail !== '' ? [$this->hotelEmail] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.enquiry-reply', with: [
            'body' => $this->body,
            'hotelName' => $this->hotelName,
            'enquiry' => $this->enquiry,
        ]);
    }
}
