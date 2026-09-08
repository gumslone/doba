<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Booking;
use App\Models\Invoice;
use App\Support\Hotel\HotelSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds an invoice from a booking's frozen figures (§5, §8).
 *
 * Two rules make the arithmetic trustworthy:
 *
 *  1. **Prices are gross.** Everything the guest was ever shown includes
 *     VAT, so VAT is EXTRACTED from the stored amount, never added to it.
 *     Adding it would silently raise the total above what the guest agreed
 *     to pay.
 *
 *  2. **Net is rounded, tax is the remainder.** net = round(gross / (1+r))
 *     and tax = gross − net, so net + tax === gross exactly, on every line
 *     and therefore on every total. Rounding both independently is how
 *     invoices end up a cent short of the payment they document.
 *
 * Nothing is recomputed from live prices: a rate change next week must not
 * alter last week's invoice.
 */
class InvoiceBuilder
{
    public function __construct(protected HotelSettings $hotel) {}

    /**
     * Issue the invoice for a booking, or return the one already issued —
     * a booking has exactly one invoice, and re-issuing would burn a
     * second number for the same stay.
     */
    public function issue(Booking $booking, ?CarbonImmutable $at = null): Invoice
    {
        $existing = Invoice::query()->where('booking_id', $booking->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $at ??= CarbonImmutable::now(config('doba.timezone'));

        $booking->loadMissing(['rooms.roomType.translations', 'extras.extra.translations', 'guest']);

        $accommodationRate = (int) $this->hotel->get('tax.accommodation_rate', 700);
        $lines = [];

        foreach ($booking->rooms as $index => $room) {
            $name = $room->roomType?->t('name', $booking->locale) ?? __('booking.room', [], $booking->locale);

            $lines[] = $this->line(
                description: sprintf(
                    '%s — %s: %s – %s',
                    $name,
                    __('booking.nights', ['count' => $booking->nights], $booking->locale),
                    $booking->check_in->toDateString(),
                    $booking->check_out->toDateString(),
                ),
                quantity: 1,
                gross: $room->price_total,
                taxRate: $accommodationRate,
                sortOrder: $index,
            );
        }

        foreach ($booking->extras as $index => $bookingExtra) {
            $lines[] = $this->line(
                description: (string) ($bookingExtra->extra?->t('name', $booking->locale) ?? '—'),
                quantity: $bookingExtra->quantity,
                gross: $bookingExtra->total,
                // Each extra carries its own rate: accommodation is
                // typically reduced while breakfast, parking and spa are
                // standard, and the invoice must show the split (§5).
                taxRate: $bookingExtra->tax_rate,
                sortOrder: 100 + $index,
            );
        }

        if ($booking->cleaning_fee > 0) {
            $lines[] = $this->line(
                description: __('invoice.cleaning_fee', [], $booking->locale),
                quantity: 1,
                gross: $booking->cleaning_fee,
                // Ancillary to the accommodation, so it follows the
                // accommodation rate unless the hotel's tax office says
                // otherwise — which is what the override setting is for.
                taxRate: (int) $this->hotel->get('tax.cleaning_rate', $accommodationRate),
                sortOrder: 800,
            );
        }

        if ($booking->city_tax > 0) {
            $lines[] = $this->line(
                description: __('invoice.city_tax', [], $booking->locale),
                quantity: 1,
                gross: $booking->city_tax,
                // Municipal visitor's tax is outside VAT in the markets
                // this targets; a zero rate keeps it on its own line in
                // the breakdown rather than hidden inside another rate.
                taxRate: 0,
                sortOrder: 900,
            );
        }

        if ($booking->discount_total > 0) {
            // A discount is a negative line at the accommodation rate, so
            // the VAT it removes leaves the breakdown consistent.
            $lines[] = $this->line(
                // Named for what earned it: a returning guest reading their
                // invoice should see why the number is lower.
                description: __($booking->loyalty_discount > 0 && $booking->promo_code_id === null
                    ? 'invoice.loyalty_discount'
                    : 'invoice.discount', [], $booking->locale),
                quantity: 1,
                gross: -$booking->discount_total,
                taxRate: $accommodationRate,
                sortOrder: 950,
            );
        }

        return DB::transaction(function () use ($booking, $lines, $at): Invoice {
            [$year, $sequence] = $this->nextNumber($at);

            $invoice = Invoice::query()->create([
                'booking_id' => $booking->id,
                'number' => sprintf('%d-%04d', $year, $sequence),
                'year' => $year,
                'sequence' => $sequence,
                'issued_at' => $at,
                'currency' => $booking->currency,
                'net_total' => array_sum(array_column($lines, 'line_net')),
                'tax_total' => array_sum(array_column($lines, 'tax_amount')),
                'gross_total' => array_sum(array_column($lines, 'line_gross')),
                'billed_to' => $this->billingAddress($booking),
            ]);

            $invoice->lines()->createMany($lines);

            return $invoice->load('lines');
        });
    }

    /**
     * @return array{description:string,quantity:int,tax_rate:int,unit_net:int,line_net:int,tax_amount:int,line_gross:int,sort_order:int}
     */
    protected function line(string $description, int $quantity, int $gross, int $taxRate, int $sortOrder): array
    {
        // Extract, never add: the stored amount already includes VAT.
        $net = (int) round($gross * 10000 / (10000 + $taxRate));

        return [
            'description' => $description,
            'quantity' => max(1, $quantity),
            'tax_rate' => $taxRate,
            'unit_net' => (int) round($net / max(1, $quantity)),
            'line_net' => $net,
            // The remainder, so net + tax is exactly the gross the guest
            // paid — never two independent roundings that drift apart.
            'tax_amount' => $gross - $net,
            'line_gross' => $gross,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * Reverse an invoice in full (§8).
     *
     * A stay cancelled after its invoice was issued leaves a document
     * that claims money nobody owes. Tax law in the markets this targets
     * does not allow that document to be deleted or edited; it allows it
     * to be REVERSED, by a second document in the same numbered series
     * that mirrors every line with the opposite sign. That is what this
     * writes: the original's lines negated, the same VAT split, a pointer
     * to the invoice it cancels, and the next number in the sequence —
     * never a number of its own, because a gapless series is the first
     * thing an audit checks.
     *
     * Idempotent: an invoice reversed twice has one credit note.
     */
    public function creditNote(Invoice $original, ?CarbonImmutable $at = null): Invoice
    {
        if ($original->isCreditNote()) {
            throw new \InvalidArgumentException("Invoice {$original->number} is itself a credit note.");
        }

        $existing = Invoice::query()->where('credits_invoice_id', $original->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $at ??= CarbonImmutable::now(config('doba.timezone'));
        $original->loadMissing('lines');

        $lines = $original->lines->map(static fn ($line): array => [
            'description' => $line->description,
            'quantity' => $line->quantity,
            'tax_rate' => $line->tax_rate,
            'unit_net' => -$line->unit_net,
            'line_net' => -$line->line_net,
            'tax_amount' => -$line->tax_amount,
            'line_gross' => -$line->line_gross,
            'sort_order' => $line->sort_order,
        ])->all();

        return DB::transaction(function () use ($original, $lines, $at): Invoice {
            [$year, $sequence] = $this->nextNumber($at);

            $note = Invoice::query()->create([
                'booking_id' => $original->booking_id,
                'kind' => Invoice::CREDIT_NOTE,
                'credits_invoice_id' => $original->id,
                'number' => sprintf('%d-%04d', $year, $sequence),
                'year' => $year,
                'sequence' => $sequence,
                'issued_at' => $at,
                'currency' => $original->currency,
                'net_total' => -$original->net_total,
                'tax_total' => -$original->tax_total,
                'gross_total' => -$original->gross_total,
                // The same recipient as the original: a credit note to a
                // different name reverses nothing.
                'billed_to' => $original->billed_to,
            ]);

            $note->lines()->createMany($lines);

            return $note->load('lines');
        });
    }

    /**
     * The next sequential number for the year (§8).
     *
     * Taken inside the caller's transaction with a locking read, so two
     * bookings confirming in the same second cannot claim one number. The
     * unique index on (year, sequence) is the backstop.
     *
     * @return array{0:int,1:int}
     */
    protected function nextNumber(CarbonImmutable $at): array
    {
        $year = (int) $at->format('Y');

        $last = Invoice::query()
            ->where('year', $year)
            ->lockForUpdate()
            ->max('sequence');

        return [$year, ((int) $last) + 1];
    }

    /**
     * @return array<string,string|null>
     */
    protected function billingAddress(Booking $booking): array
    {
        $guest = $booking->guest;

        if ($guest === null) {
            return [];
        }

        return array_filter([
            'name' => trim($guest->first_name.' '.$guest->last_name) ?: null,
            'email' => $guest->email,
            'address' => $guest->address,
            'postal_code' => $guest->postal_code,
            'city' => $guest->city,
            'country' => $guest->country,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
