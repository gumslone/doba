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
 * The notification to the hotel's own inbox. Queued (§13): a slow SMTP
 * server must never delay the guest's thank-you page.
 */
class EnquiryReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Enquiry $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('contact.mail_subject', ['name' => $this->enquiry->name]),
            replyTo: [$this->enquiry->email],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.enquiry');
    }
}
