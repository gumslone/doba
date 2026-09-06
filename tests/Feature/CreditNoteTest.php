<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Invoicing\InvoiceBuilder;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use Carbon\CarbonImmutable;

/**
 * Credit notes (§8): the document that reverses an invoice a cancelled
 * stay left behind, in the same numbered series.
 */
function invoicedStay(): Booking
{
    $roomType = RoomType::query()->firstOr(fn () => RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 3,
    ]));

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    foreach (range(0, 14) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
            ['allotment' => 3],
        );
    }

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => uniqid().'@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2,
    );

    // Confirming issues the invoice.
    return app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test')->fresh();
}

it('reverses the invoice when a confirmed stay is cancelled', function (): void {
    $booking = invoicedStay();
    $invoice = $booking->invoice;

    expect($invoice)->not->toBeNull();

    app(BookingService::class)->transition($booking, BookingStatus::Cancelled, 'guest called');

    $note = $booking->fresh()->creditNote;

    expect($note)->not->toBeNull()
        ->and($note->kind)->toBe(Invoice::CREDIT_NOTE)
        ->and($note->credits_invoice_id)->toBe($invoice->id)
        // Every amount mirrored with the opposite sign — the VAT split too.
        ->and($note->gross_total)->toBe(-$invoice->gross_total)
        ->and($note->net_total)->toBe(-$invoice->net_total)
        ->and($note->tax_total)->toBe(-$invoice->tax_total)
        ->and($note->lines->count())->toBe($invoice->lines->count())
        ->and($note->lines->sum('line_gross'))->toBe(-$invoice->lines->sum('line_gross'))
        // The next number in the SAME series: a gapless sequence is the
        // first thing an audit checks.
        ->and($note->sequence)->toBe($invoice->sequence + 1)
        ->and($note->billed_to)->toBe($invoice->billed_to);

    // The original is untouched — reversed, never edited or deleted.
    expect($invoice->fresh()->gross_total)->toBe(20000)
        ->and($booking->fresh()->invoice->id)->toBe($invoice->id);
});

it('issues no credit note where there was no invoice', function (): void {
    $roomType = RoomType::create(['code' => 'SGL', 'base_occupancy' => 1, 'max_occupancy' => 1, 'default_rate' => 8000, 'total_units' => 1]);
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(3);

    foreach (range(0, 5) as $i) {
        Availability::create(['room_type_id' => $roomType->id, 'date' => $checkIn->addDays($i)->toDateString(), 'allotment' => 1]);
    }

    // Pending, never confirmed, never invoiced.
    $booking = app(BookingService::class)->place($roomType, $checkIn, $checkIn->addDay(),
        ['email' => 'p@example.com', 'first_name' => 'P', 'last_name' => 'Q'], adults: 1);

    app(BookingService::class)->transition($booking, BookingStatus::Cancelled, 'abandoned');

    expect(Invoice::query()->count())->toBe(0);
});

it('reverses an invoice once, whatever happens twice', function (): void {
    $booking = invoicedStay();

    app(InvoiceBuilder::class)->creditNote($booking->invoice);
    app(InvoiceBuilder::class)->creditNote($booking->invoice);

    expect(Invoice::query()->where('kind', Invoice::CREDIT_NOTE)->count())->toBe(1);

    // And a credit note cannot itself be reversed.
    expect(fn () => app(InvoiceBuilder::class)->creditNote($booking->fresh()->creditNote))
        ->toThrow(InvalidArgumentException::class);
});

it('prints as a credit note that names what it reverses, for the guest and the admin', function (): void {
    config()->set('doba.locales', ['en']);
    $booking = invoicedStay();
    app(BookingService::class)->transition($booking, BookingStatus::Cancelled, 'test');

    $booking = $booking->fresh();

    // The guest's manage page offers both documents.
    $page = $this->get("/en/booking/manage/{$booking->reference}/{$booking->manage_token}")->assertOk();
    $page->assertSee('Download credit note '.$booking->creditNote->number);

    $this->get("/en/booking/manage/{$booking->reference}/{$booking->manage_token}/credit-note.pdf")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // The document itself says what it is and what it reverses.
    $html = view('invoices.document', ['invoice' => $booking->creditNote->load('lines', 'creditsInvoice'), 'locale' => 'en', 'hotel' => app(HotelSettings::class), 'money' => fn ($v) => (string) $v])->render();

    expect($html)->toContain('Credit note')->toContain('Reverses invoice '.$booking->invoice->number);

    // And the admin list labels it.
    $this->actingAs(User::factory()->create())->get('/admin/invoices')->assertOk()->assertSee('credit note');
});
