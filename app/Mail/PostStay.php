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
 * The thank-you, the morning after departure (§13).
 *
 * Short on purpose: thanks, the invoice link for the expense report,
 * and nothing that smells of a campaign. A guest who has just left is
 * the likeliest to book directly next time — unless the first thing the
 * hotel does with their address is treat it as a marketing asset.
 */
class PostStay extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_stay_subject', [
                'hotel' => app(HotelSettings::class)->name,
            ], $this->booking->locale),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.post-stay', with: [
            'booking' => $this->booking,
            'manageUrl' => Localization::route('booking.manage', [
                'reference' => $this->booking->reference,
                'token' => $this->booking->manage_token,
            ], $this->booking->locale),
        ]);
    }
}
