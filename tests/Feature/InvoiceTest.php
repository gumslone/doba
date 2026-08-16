<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Invoicing\InvoiceBuilder;
use App\Enums\BookingStatus;
use App\Mail\BookingConfirmed;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\Invoice;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Setting::put('tax', 'accommodation_rate', 700);

    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'default_rate' => 10000,
        'total_units' => 3,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 3) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    $this->service = app(BookingService::class);
    $this->builder = app(InvoiceBuilder::class);

    $this->book = fn (): Booking => $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(3),
        ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'Kowalska'],
        adults: 2,
    );
});

it('extracts VAT from the gross price instead of adding it to it', function (): void {
    // €300 gross at the 7% reduced accommodation rate.
    $invoice = $this->builder->issue(($this->book)());

    expect($invoice->gross_total)->toBe(30000)
        // 30000 / 1.07 = 28037.38 → 28037, and the tax is the remainder.
        ->and($invoice->net_total)->toBe(28037)
        ->and($invoice->tax_total)->toBe(1963);
});

it('never lets net and tax drift away from the gross the guest paid', function (): void {
    $booking = ($this->book)();

    // Two rates on one invoice: 7% accommodation, 19% breakfast.
    $breakfast = Extra::create([
        'code' => 'BREAKFAST', 'price' => 1850, 'applies_per' => 'person_night',
        'tax_rate' => 1900, 'max_quantity' => 2, 'is_active' => true,
    ]);
    $breakfast->translations()->create(['locale' => 'en', 'name' => 'Breakfast']);

    $this->service->addExtras($booking, [$breakfast->id => 1]);

    $invoice = $this->builder->issue($booking->fresh());

    foreach ($invoice->lines as $line) {
        expect($line->line_net + $line->tax_amount)->toBe($line->line_gross);
    }

    expect($invoice->net_total + $invoice->tax_total)->toBe($invoice->gross_total)
        // And the document bills exactly what the booking says is owed.
        ->and($invoice->gross_total)->toBe($booking->fresh()->total);
});

it('groups the breakdown by rate so each VAT band is shown separately', function (): void {
    $booking = ($this->book)();

    $breakfast = Extra::create([
        'code' => 'BREAKFAST', 'price' => 1850, 'applies_per' => 'person_night',
        'tax_rate' => 1900, 'max_quantity' => 2, 'is_active' => true,
    ]);
    $breakfast->translations()->create(['locale' => 'en', 'name' => 'Breakfast']);

    $this->service->addExtras($booking, [$breakfast->id => 1]);

    $bands = $this->builder->issue($booking->fresh())->taxBreakdown();

    expect($bands)->toHaveCount(2)
        ->and($bands[0]['rate'])->toBe(700)     // accommodation, sorted first
        ->and($bands[1]['rate'])->toBe(1900);   // breakfast

    foreach ($bands as $band) {
        expect($band['net'] + $band['tax'])->toBe($band['gross']);
    }

    // 3 nights × 2 guests × €18.50 = €111 gross at 19%.
    expect($bands[1]['gross'])->toBe(11100);
});

it('numbers invoices sequentially within the year and never reuses a number', function (): void {
    $at = CarbonImmutable::create(2026, 3, 4, 12);

    $first = $this->builder->issue(($this->book)(), $at);
    $second = $this->builder->issue(($this->book)(), $at);
    $nextYear = $this->builder->issue(($this->book)(), $at->addYear());

    expect($first->number)->toBe('2026-0001')
        ->and($second->number)->toBe('2026-0002')
        // The sequence restarts per year, as the number format implies.
        ->and($nextYear->number)->toBe('2027-0001');
});

it('issues exactly one invoice per booking', function (): void {
    $booking = ($this->book)();

    $first = $this->builder->issue($booking);
    $again = $this->builder->issue($booking->fresh());

    expect($again->id)->toBe($first->id)
        ->and(Invoice::query()->count())->toBe(1);
});

it('issues the invoice when the booking is confirmed', function (): void {
    $booking = ($this->book)();

    expect($booking->invoice()->exists())->toBeFalse();

    $this->service->transition($booking, BookingStatus::Confirmed);

    expect($booking->fresh()->invoice)->not->toBeNull();
});

it('freezes the guest address on the invoice rather than following the guest record', function (): void {
    $booking = ($this->book)();
    $invoice = $this->builder->issue($booking);

    $booking->guest->update(['last_name' => 'Nowak']);

    expect($invoice->fresh()->billed_to['name'])->toBe('Anna Kowalska');
});

it('renders a PDF and serves it only to an authenticated admin', function (): void {
    Storage::fake('local');

    $invoice = $this->builder->issue(($this->book)());

    $this->get("/admin/invoices/{$invoice->id}.pdf")->assertRedirect('/admin/login');

    $response = $this->actingAs(User::factory()->create())
        ->get("/admin/invoices/{$invoice->id}.pdf");

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');

    // Stored on the private disk: it carries the guest's name and address.
    expect(Storage::disk('local')->exists($invoice->fresh()->pdf_path))->toBeTrue();
});

it('keeps a rendered invoice unchanged when prices later move', function (): void {
    $invoice = $this->builder->issue(($this->book)());

    $this->roomType->update(['default_rate' => 25000]);

    expect($invoice->fresh()->gross_total)->toBe(30000);
});

it('lets the guest download their own invoice but nobody else', function (): void {
    Storage::fake('local');

    $booking = ($this->book)();
    $invoice = $this->builder->issue($booking);

    $url = "/en/booking/manage/{$booking->reference}/{$booking->manage_token}/invoice.pdf";

    $response = $this->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF-')
        // Never cached by a shared proxy: it has the guest's address on it.
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // The token is the only credential, so a wrong one must reveal nothing.
    $this->get("/en/booking/manage/{$booking->reference}/".str_repeat('a', 40).'/invoice.pdf')
        ->assertNotFound();

    expect($invoice->number)->not->toBeEmpty();
});

it('attaches the invoice to the confirmation mail', function (): void {
    Mail::fake();

    $booking = ($this->book)();
    $this->service->transition($booking, BookingStatus::Confirmed);

    Mail::assertQueued(BookingConfirmed::class, function (BookingConfirmed $mail) use ($booking): bool {
        $attachments = $mail->attachments();

        return $mail->booking->is($booking)
            && count($attachments) === 1
            && str_ends_with($attachments[0]->as, '.pdf');
    });
});

it('refuses to delete a booking that has been invoiced', function (): void {
    $booking = ($this->book)();
    $this->builder->issue($booking);

    // An issued number is a tax record; the schema is the last line of
    // defence against it disappearing with the stay it documents.
    expect(fn () => $booking->delete())->toThrow(QueryException::class);
});
