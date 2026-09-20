<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\GiftVoucher;
use App\Support\Hotel\HotelSettings;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the buyer, right after ordering: how to pay. The voucher itself
 * follows only once the money has arrived.
 */
class VoucherOrdered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GiftVoucher $voucher) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('vouchers.mail_ordered_subject', ['hotel' => app(HotelSettings::class)->name], $this->voucher->locale));
    }

    public function content(): Content
    {
        $hotel = app(HotelSettings::class);

        return new Content(markdown: 'emails.voucher-ordered', with: [
            'voucher' => $this->voucher,
            'amount' => Money::exact($this->voucher->initial_amount, $this->voucher->currency, $this->voucher->locale),
            'instructions' => (string) $hotel->get('vouchers.payment_instructions', ''),
            'hotelName' => $hotel->name,
        ]);
    }
}
