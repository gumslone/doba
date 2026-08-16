<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use App\Support\Routing\Localization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The guest's confirmation (§13), rendered in the language they booked in
 * — not the language of whoever's request happens to trigger the send,
 * which for a webhook is the provider's.
 *
 * Queued: a slow SMTP server must never delay a booking.
 */
class BookingConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.booking_subject', [
                'reference' => $this->booking->reference,
            ], $this->booking->locale),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.booking-confirmed', with: [
            'booking' => $this->booking,
            'manageUrl' => Localization::route('booking.manage', [
                'reference' => $this->booking->reference,
                'token' => $this->booking->manage_token,
            ], $this->booking->locale),
        ]);
    }
}
