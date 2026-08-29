<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We are looking forward to you" — a few days out (§13).
 *
 * The mail a good front desk sends by hand and a tired one forgets. It
 * carries the three things a guest checks their inbox for the night
 * before travelling: when they can get in, how to reach the hotel, and
 * whether anything is still owed — with the way to settle it one click
 * away, so nobody starts their holiday at the desk discussing money.
 */
class PreArrival extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.pre_arrival_subject', [
                'hotel' => app(HotelSettings::class)->name,
            ], $this->booking->locale),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.pre-arrival', with: [
            'booking' => $this->booking,
            'manageUrl' => Localization::route('booking.manage', [
                'reference' => $this->booking->reference,
                'token' => $this->booking->manage_token,
            ], $this->booking->locale),
        ]);
    }
}
