<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Models\ApiClient;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\RoomType;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The municipal visitor's tax (§7): per person, per night, on its own
 * line — the Kurtaxe/Ortstaxe/taxe de séjour most European
 * municipalities levy. The column, the invoice line and the total
 * arithmetic existed from the start; this pins the part that computes
 * it, because a hotel that under-collects a municipal tax owes the
 * difference out of its own pocket.
 */
function taxedStay(int $adults = 2, int $children = 1, int $nights = 3): Booking
{
    $roomType = RoomType::create([
        'code' => 'DBL-'.uniqid(),
        'base_occupancy' => 2, 'max_occupancy' => 4,
        'max_adults' => 3, 'max_children' => 2,
        'default_rate' => 10000, 'total_units' => 2,
    ]);

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(14);

    foreach (range(0, $nights) as $i) {
        Availability::create([
            'room_type_id' => $roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    return app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays($nights),
        ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: $adults, children: $children,
    );
}

it('charges per adult per night, with children exempt by default', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 250);   // €2.50

    $booking = taxedStay(adults: 2, children: 1, nights: 3);

    // 2 adults × 3 nights × €2.50 — the child sleeps tax-free, as most
    // municipal rules have it.
    expect($booking->city_tax)->toBe(1500)
        ->and($booking->total)->toBe(30000 + 1500)
        ->and($booking->balance_due)->toBe(31500)
        // The deposit secures the room; the tax is settled with the stay.
        ->and($booking->deposit_due)->toBe(30000);
});

it('taxes every sleeper where the municipality says so', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 250);
    config()->set('doba.taxes.city_tax_children_exempt', false);

    expect(taxedStay(adults: 2, children: 1, nights: 3)->city_tax)->toBe(2250);
});

it('stays entirely out of the way when no tax is configured', function (): void {
    $booking = taxedStay();

    expect($booking->city_tax)->toBe(0)
        ->and($booking->total)->toBe(30000);
});

it('survives extras being added without being doubled or dropped', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 200);

    $booking = taxedStay(adults: 2, children: 0, nights: 2);   // tax = 800

    $extra = Extra::create(['code' => 'BRK-'.uniqid(), 'price' => 1500, 'charge_type' => 'per_stay', 'tax_rate' => 1000]);

    app(BookingService::class)->addExtras($booking, [$extra->id => 1]);

    $booking->refresh();

    expect($booking->city_tax)->toBe(800)
        ->and($booking->total)->toBe(20000 + 1500 + 800);
});

it('prints the tax on its own invoice line, outside VAT', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 250);

    $booking = taxedStay(adults: 2, children: 0, nights: 2);
    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    $invoice = $booking->fresh()->invoice;
    $taxLine = $invoice->lines->firstWhere('tax_rate', 0);

    // Shown separately, as the municipality requires — and at 0% VAT,
    // because a visitor's tax is not the hotel's revenue.
    expect($taxLine)->not->toBeNull()
        ->and($taxLine->line_gross)->toBe(1000)
        ->and($invoice->gross_total)->toBe($booking->total);
});

it('shows the guest the tax before they book, not after', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 250);
    config()->set('doba.locales', ['en']);

    $booking = taxedStay();   // creates the room type + availability
    $roomType = RoomType::sole();
    $booking->delete();

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(14);

    // 2 adults + 1 child (exempt), 2 nights => €10 of tax on the page.
    $this->get('/en/booking/checkout?'.http_build_query([
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 1,
        'room_type' => $roomType->id,
    ]))->assertOk()
        ->assertSee('City tax')
        ->assertSee(e(Money::format(1000)), false);
});

it('reports it to API partners as its own field', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 250);

    ['client' => $client, 'secret' => $secret] = ApiClient::issue('CM', ApiClient::SCOPES);

    $booking = taxedStay(adults: 2, children: 0, nights: 2);

    $this->getJson('/api/v1/bookings/'.$booking->reference, [
        'X-Api-Key-Id' => $client->key_id,
        'X-Api-Secret' => $secret,
    ])->assertOk()
        ->assertJsonPath('data.city_tax', ['amount' => 1000, 'currency' => 'EUR']);
});
