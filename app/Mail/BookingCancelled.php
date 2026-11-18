<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use App\Support\Hotel\HotelSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Word to the guest that their stay is cancelled (§13), in the language
 * they booked in, and — the part people actually want to read — what
 * happens to their money.
 *
 * There was no such mail. A guest who pressed Cancel saw one page say so
 * and then had nothing: no record to show a card issuer, no statement of
 * whether a refund was coming.
 */
class BookingCancelled extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  int  $restoredToVoucher  what already went back onto a gift voucher, in minor units
     */
    public function __construct(public Booking $booking, public int $restoredToVoucher = 0) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.cancelled_subject', [
                'reference' => $this->booking->reference,
                'hotel' => app(HotelSettings::class)->name,
            ], $this->booking->locale),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.booking-cancelled', with: [
            'booking' => $this->booking,
            'restored' => $this->restoredToVoucher,
        ]);
    }
}
