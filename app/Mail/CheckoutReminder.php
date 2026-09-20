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
 * "Your room is still free" — to somebody who got as far as their name
 * and then closed the tab (§13).
 *
 * One mail, an hour or so later, only while the room can still be had,
 * and it says plainly that nothing was charged and nothing is held. The
 * link reopens checkout on the same room and dates, so finishing is one
 * click and a card number rather than starting again.
 */
class CheckoutReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    /**
     * @return array<string,mixed>
     */
    protected function words(): array
    {
        return [
            'name' => $this->booking->guest?->first_name,
            'hotel' => app(HotelSettings::class)->name,
            'date' => $this->booking->check_in->translatedFormat('l, j M Y'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: Wording::text('recovery_subject', $this->words(), $this->booking->locale),
        );
    }

    public function content(): Content
    {
        $room = $this->booking->rooms->first();

        return new Content(markdown: 'emails.checkout-reminder', with: [
            'booking' => $this->booking,
            'words' => $this->words(),
            'resumeUrl' => Localization::route('booking.checkout', array_filter([
                'room_type' => $room?->room_type_id,
                'check_in' => $this->booking->check_in->toDateString(),
                'check_out' => $this->booking->check_out->toDateString(),
                'adults' => $this->booking->adults,
                'children' => $this->booking->children ?: null,
                'units' => $this->booking->rooms->count() > 1 ? $this->booking->rooms->count() : null,
            ], static fn ($v): bool => $v !== null), $this->booking->locale),
        ]);
    }
}
