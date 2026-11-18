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
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The hotel's own copy of the news (§13): a booking came in, or one was
 * cancelled, by somebody who is not sitting at the front desk.
 *
 * Sent to the address on the contact page — the one inbox a small hotel
 * certainly reads — in the site's own language. Short on purpose: it is
 * read on a phone, between two other things, and its job is to make the
 * person open the booking.
 */
class BookingNotice extends Mailable
{
    use Queueable, SerializesModels;

    public const BOOKED = 'booked';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public Booking $booking,
        public string $event,
        public int $restoredToVoucher = 0,
    ) {}

    /**
     * Queue it, if the hotel wants these and has somewhere to read them.
     *
     * Never throws: a notice is a courtesy to the hotel, and failing to
     * send one must not fail the booking it is about.
     */
    public static function notify(Booking $booking, string $event, int $restoredToVoucher = 0): void
    {
        if (! (bool) config('doba.notify.bookings', true)) {
            return;
        }

        try {
            $inbox = (string) (config('doba.notify.email') ?: app(HotelSettings::class)->get('contact.email'));

            if ($inbox === '') {
                return;
            }

            Mail::to($inbox)->queue(new self($booking, $event, $restoredToVoucher));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.app(HotelSettings::class)->name.'] '.__('mail.notice_'.$this->event.'_subject', [
                'reference' => $this->booking->reference,
                'name' => trim($this->booking->guest?->first_name.' '.$this->booking->guest?->last_name),
                'date' => $this->booking->check_in->translatedFormat('j M'),
            ], (string) config('app.locale')),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.booking-notice', with: [
            'booking' => $this->booking,
            'event' => $this->event,
            'restored' => $this->restoredToVoucher,
            'url' => url('/admin/bookings/'.$this->booking->id.'/edit'),
            'locale' => (string) config('app.locale'),
        ]);
    }
}
