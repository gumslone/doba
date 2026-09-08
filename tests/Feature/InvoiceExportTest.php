<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Invoicing\InvoiceBuilder;
use App\Enums\AppliesPer;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Extra;
use App\Models\Invoice;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The invoice CSV (§5): what the accountant gets at the end of the month.
 */
function exportedInvoice(int $daysAgo, ?string $email = null): Invoice
{
    $roomType = RoomType::query()->where('code', 'CSV')->firstOr(function (): RoomType {
        $type = RoomType::create(['code' => 'CSV', 'base_occupancy' => 2, 'max_occupancy' => 2, 'default_rate' => 10000, 'total_units' => 5]);
        $type->translations()->create(['locale' => 'en', 'slug' => 'csv', 'name' => 'Double']);

        foreach (range(0, 14) as $i) {
            Availability::create(['room_type_id' => $type->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString(), 'allotment' => 5]);
        }

        return $type;
    });

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(5);

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => $email ?? uniqid().'@example.com', 'first_name' => 'Jürgen', 'last_name' => 'Müller', 'country' => 'DE'],
        adults: 2,
    );

    $invoice = app(InvoiceBuilder::class)->issue($booking);
    $invoice->forceFill(['issued_at' => CarbonImmutable::today(config('doba.timezone'))->subDays($daysAgo)->setTime(10, 0)])->save();

    return $invoice->fresh();
}

function csvRows(string $body): array
{
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

    return array_map('str_getcsv', array_filter(explode("\n", trim($body))));
}

beforeEach(function (): void {
    Setting::put('tax', 'accommodation_rate', 700);
    $this->admin = User::factory()->create();
});

it('keeps the export behind the admin session', function (): void {
    $this->get('/admin/invoices/export')->assertRedirect('/admin/login');
});

it('exports one decimal row per document in the period, VAT split by rate', function (): void {
    $inside = exportedInvoice(3);
    $outside = exportedInvoice(40);

    // A second rate in the period: breakfast at 19% on top of the room at 7%.
    $extra = Extra::create(['code' => 'BF', 'price' => 1500, 'tax_rate' => 1900, 'applies_per' => AppliesPer::cases()[0], 'is_active' => true]);
    $withBreakfast = exportedInvoice(2);
    $booking = $withBreakfast->booking;
    app(BookingService::class)->addExtras($booking, [$extra->id => 1]);
    $withBreakfast->delete();
    $withBreakfast = app(InvoiceBuilder::class)->issue($booking->fresh());
    $withBreakfast->forceFill(['issued_at' => CarbonImmutable::today(config('doba.timezone'))->subDays(2)->setTime(10, 0)])->save();

    $today = CarbonImmutable::today(config('doba.timezone'));

    $response = $this->actingAs($this->admin)->get('/admin/invoices/export?'.http_build_query([
        'from' => $today->subDays(10)->toDateString(),
        'to' => $today->toDateString(),
    ]))->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertDownload(sprintf('doba-invoices-%s-to-%s.csv', $today->subDays(10)->toDateString(), $today->toDateString()));

    $body = $response->streamedContent();

    expect($body)->toStartWith("\xEF\xBB\xBF");

    $rows = csvRows($body);
    $header = $rows[0];

    expect($header)->toBe(['number', 'kind', 'issued_on', 'booking', 'billed_to', 'country', 'currency', 'net', 'tax', 'gross', 'credits', 'net_7', 'tax_7', 'net_19', 'tax_19'])
        ->and(count($rows))->toBe(3)
        ->and(collect($rows)->pluck(0))->toContain($inside->number)
        ->and(collect($rows)->pluck(0))->not->toContain($outside->number);

    $row = array_combine($header, collect($rows)->first(fn (array $r): bool => $r[0] === $inside->number));

    expect($row['kind'])->toBe('invoice')
        ->and($row['issued_on'])->toBe($today->subDays(3)->toDateString())
        ->and($row['booking'])->toBe($inside->booking->reference)
        ->and($row['billed_to'])->toContain('Müller')
        ->and($row['country'])->toBe('DE')
        ->and($row['gross'])->toBe('200.00')
        ->and($row['net'])->toBe(Money::decimal($inside->net_total))
        ->and($row['tax'])->toBe(Money::decimal($inside->tax_total))
        ->and($row['net_7'])->toBe($row['net'])
        ->and($row['tax_19'])->toBe('0.00');

    $bf = array_combine($header, collect($rows)->first(fn (array $r): bool => $r[0] === $withBreakfast->number));

    expect((float) $bf['net_19'])->toBeGreaterThan(0)
        ->and(round((float) $bf['net_7'] + (float) $bf['net_19'], 2))->toBe((float) $bf['net']);
});

it('exports a credit note as a negative row that names what it reverses', function (): void {
    $invoice = exportedInvoice(1);
    app(BookingService::class)->transition($invoice->booking, BookingStatus::Confirmed, 'test');
    $note = app(InvoiceBuilder::class)->creditNote($invoice);
    $note->forceFill(['issued_at' => CarbonImmutable::today(config('doba.timezone'))->setTime(11, 0)])->save();

    $rows = csvRows($this->actingAs($this->admin)->get('/admin/invoices/export')->assertOk()->streamedContent());
    $header = $rows[0];
    $row = array_combine($header, collect($rows)->first(fn (array $r): bool => $r[0] === $note->number));

    expect($row['kind'])->toBe('credit_note')
        ->and($row['gross'])->toBe('-200.00')
        ->and($row['credits'])->toBe($invoice->number);
});

it('defaults to the current month and reads an inverted range as the typo it is', function (): void {
    exportedInvoice(0);
    $today = CarbonImmutable::today(config('doba.timezone'));

    $this->actingAs($this->admin)->get('/admin/invoices/export')
        ->assertDownload(sprintf('doba-invoices-%s-to-%s.csv', $today->startOfMonth()->toDateString(), $today->toDateString()));

    $this->actingAs($this->admin)->get('/admin/invoices/export?from='.$today->toDateString().'&to='.$today->subDays(5)->toDateString())
        ->assertDownload(sprintf('doba-invoices-%s-to-%s.csv', $today->subDays(5)->toDateString(), $today->toDateString()));
});
