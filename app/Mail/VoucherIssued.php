<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Vouchers\VoucherRenderer;
use App\Models\GiftVoucher;
use App\Support\Hotel\HotelSettings;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the buyer, once the voucher is worth something: the PDF to print or
 * forward. Sent to the buyer, never the recipient — it is a present, and
 * the giver decides when it is opened.
 */
class VoucherIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GiftVoucher $voucher) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('vouchers.mail_issued_subject', ['hotel' => app(HotelSettings::class)->name], $this->voucher->locale));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.voucher-issued', with: [
            'voucher' => $this->voucher,
            'amount' => Money::exact($this->voucher->initial_amount, $this->voucher->currency, $this->voucher->locale),
            'hotelName' => app(HotelSettings::class)->name,
        ]);
    }

    /**
     * @return array<int,Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => app(VoucherRenderer::class)->render($this->voucher),
                $this->voucher->code.'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
