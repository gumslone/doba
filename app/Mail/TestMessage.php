<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The message the admin sends to prove mail works (§16 step 5).
 *
 * Sent synchronously, never queued: the whole purpose is to find out
 * whether sending works *now*, and a queued test that fails in a worker
 * an hour later reports success to the person watching.
 */
class TestMessage extends Mailable
{
    public function __construct(public string $hotel, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.test_subject', ['hotel' => $this->hotel]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.test', with: [
            // Not `hotel`: a global view composer shares the HotelSettings
            // object under that name with every view, and it wins — the
            // template rendered the object into mb_substr and threw.
            'hotelName' => $this->hotel,
            // A code shown on screen and repeated in the message. It is
            // what stops "yes I got it" meaning an older test, or a
            // message from a different install entirely.
            'code' => $this->code,
        ]);
    }
}
