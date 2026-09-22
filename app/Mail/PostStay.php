<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\Wording;
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
            subject: Wording::text('post_stay_subject', [
                'hotel' => app(HotelSettings::class)->name,
                'name' => $this->booking->guest?->first_name,
            ], $this->booking->locale),
        );
    }

    public function content(): Content
    {
        // "Come back": a returning-guest discount they now qualify for is
        // the best reason to book direct next time, so it is said here,
        // once, with the link that will apply it.
        $bps = (int) config('doba.loyalty.discount_bps', 0);
        $qualifies = $bps > 0 && $this->booking->guest !== null
            && $this->booking->guest->stays_count >= max(1, (int) config('doba.loyalty.min_stays', 1));

        return new Content(markdown: 'emails.post-stay', with: [
            'booking' => $this->booking,
            'loyaltyPercent' => $qualifies ? rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.') : null,
            'bookAgainUrl' => Localization::route('rooms.index', [], $this->booking->locale),
            'manageUrl' => Localization::route('booking.manage', [
                'reference' => $this->booking->reference,
                'token' => $this->booking->manage_token,
            ], $this->booking->locale),
        ]);
    }
}
