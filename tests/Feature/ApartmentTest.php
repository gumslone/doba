<?php

declare(strict_types=1);

use App\Domain\Availability\AvailabilityService;
use App\Domain\Booking\BookingService;
use App\Domain\Invoicing\InvoiceBuilder;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use App\Support\Install\RoomBuilder;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Apartments (§5): a self-catering unit sold through the same funnel as
 * a room. What is pinned here is exactly what differs — a minimum stay
 * the type itself insists on, a cleaning fee charged once per unit per
 * stay instead of per night, and the facts a guest is told.
 */
function seedApartment(int $cleaningFee = 6000, int $minNights = 3, int $units = 2): RoomType
{
    $type = RoomType::create([
        'code' => 'APT-'.uniqid(),
        'kind' => RoomType::APARTMENT,
        'base_occupancy' => 2, 'max_occupancy' => 5,
        'max_adults' => 4, 'max_children' => 3,
        'bedrooms' => 2, 'bathrooms' => 1,
        'default_rate' => 10000,
        'cleaning_fee' => $cleaningFee,
        'min_nights' => $minNights,
        'total_units' => $units,
    ]);

    $type->translations()->create(['locale' => 'en', 'slug' => 'lake-flat-'.$type->id, 'name' => 'Lake flat']);

    $from = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    foreach (range(0, 14) as $i) {
        Availability::create([
            'room_type_id' => $type->id,
            'date' => $from->addDays($i)->toDateString(),
            'allotment' => $units,
        ]);
    }

    return $type;
}

function apartmentStay(RoomType $type, int $nights = 3, int $units = 1, int $adults = 2): Booking
{
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    return app(BookingService::class)->place(
        $type, $checkIn, $checkIn->addDays($nights),
        ['email' => 'flat@example.com', 'first_name' => 'Ola', 'last_name' => 'K'],
        adults: $adults, units: $units,
    );
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en']);
});

it('refuses a stay shorter than the minimum the type insists on', function (): void {
    $type = seedApartment(minNights: 3);
    $availability = app(AvailabilityService::class);
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    expect($availability->isBookable($type, $checkIn, $checkIn->addDays(2)))->toBeFalse()
        ->and($availability->isBookable($type, $checkIn, $checkIn->addDays(3)))->toBeTrue();

    // The search simply does not offer it for two nights — no flat
    // appears with a price the guest then cannot book.
    $twoNights = collect($availability->search($checkIn, $checkIn->addDays(2)))->pluck('room_type.id');
    $threeNights = collect($availability->search($checkIn, $checkIn->addDays(3)))->pluck('room_type.id');

    expect($twoNights)->not->toContain($type->id)
        ->and($threeNights)->toContain($type->id);

    // And the calendar the date picker reads says so on every day.
    $day = $availability->calendar($type, $checkIn, $checkIn)[0];
    expect($day['min_stay'])->toBe(3);
});

it('charges the cleaning fee once per unit per stay, inside the total and the deposit', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 250);

    $booking = apartmentStay(seedApartment(cleaningFee: 6000), nights: 3, units: 2, adults: 2);

    // 2 units × 3 nights × €100, plus 2 × €60 cleaning — once each,
    // not once per night — plus 2 adults × 3 nights × €2.50 tax.
    expect($booking->subtotal)->toBe(60000)
        ->and($booking->cleaning_fee)->toBe(12000)
        ->and($booking->city_tax)->toBe(1500)
        ->and($booking->total)->toBe(60000 + 12000 + 1500)
        ->and($booking->balance_due)->toBe(73500)
        // The fee is part of the accommodation price the deposit secures;
        // the tax is settled with the stay.
        ->and($booking->deposit_due)->toBe(72000);
});

it('charges nothing extra for a room', function (): void {
    $type = seedApartment(cleaningFee: 0, minNights: 1);
    $type->update(['kind' => RoomType::ROOM]);

    $booking = apartmentStay($type, nights: 2);

    expect($booking->cleaning_fee)->toBe(0)
        ->and($booking->total)->toBe(20000);
});

it('puts the cleaning fee on its own invoice line, at the accommodation rate unless told otherwise', function (): void {
    $booking = apartmentStay(seedApartment(cleaningFee: 6000));
    $invoice = app(InvoiceBuilder::class)->issue($booking);

    $line = $invoice->lines->firstWhere('description', 'Final cleaning');

    expect($line)->not->toBeNull()
        ->and($line->line_gross)->toBe(6000)
        ->and($line->tax_rate)->toBe(700)
        ->and($invoice->lines->sum('line_gross'))->toBe($booking->total);

    // A tax office that wants the standard rate on cleaning gets it,
    // without touching the accommodation rate.
    Setting::put('tax', 'cleaning_rate', '1900');
    app(HotelSettings::class)->refresh();

    $second = app(InvoiceBuilder::class)->issue(apartmentStay(seedApartment(cleaningFee: 6000)));

    expect($second->lines->firstWhere('description', 'Final cleaning')->tax_rate)->toBe(1900)
        ->and($second->lines->first()->tax_rate)->toBe(700);
});

it('refunds the cleaning fee together with the rooms', function (): void {
    $booking = apartmentStay(seedApartment(cleaningFee: 6000));
    $booking->forceFill(['paid_amount' => $booking->total])->save();

    // No plan, so refundable: nobody cleans a flat that was never slept in.
    expect(app(BookingService::class)->refundableAmount($booking->fresh()))->toBe(30000 + 6000);
});

it('keeps the fee when the desk moves the dates', function (): void {
    $booking = apartmentStay(seedApartment(cleaningFee: 6000), nights: 3);
    $checkIn = $booking->check_in;

    $changed = app(BookingService::class)->changeStay($booking, $checkIn, $checkIn->addDays(4));

    expect($changed->nights)->toBe(4)
        ->and($changed->cleaning_fee)->toBe(6000)
        ->and($changed->total)->toBe(40000 + 6000);
});

it('shows the fee on the checkout page before the booking exists', function (): void {
    $type = seedApartment(cleaningFee: 6000);
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    $response = $this->get('/en/booking/checkout?'.http_build_query([
        'room_type' => $type->id,
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(3)->toDateString(),
        'adults' => 2,
    ]))->assertOk();

    $response->assertSee('Cleaning fee')
        ->assertSee(Money::format(6000))
        // The total the guest sees is the total they will owe.
        ->assertSee(Money::format(36000));
});

it('publishes an apartment as schema.org/Apartment with its bedrooms', function (): void {
    $type = seedApartment();
    $slug = $type->translations->first()->slug;

    $html = $this->get('/en/rooms/'.$slug)->assertOk()->getContent();
    $node = collect(jsonLdBlocks($html))->firstWhere('@type', 'Apartment');

    expect($node)->not->toBeNull()
        ->and($node['numberOfBedrooms'])->toBe(2)
        ->and($node['numberOfBathroomsTotal'])->toBe(1)
        ->and($node['occupancy']['maxValue'])->toBe(5);

    // What the page says, in words: the kind, the rooms, the fee, the minimum.
    $this->get('/en/rooms/'.$slug)
        ->assertSee('Apartment')
        ->assertSee('2 bedrooms')
        ->assertSee('Minimum stay')
        ->assertSee('Cleaning fee');
});

it('tells the directory what kind of unit it is', function (): void {
    seedApartment(cleaningFee: 6000, minNights: 3);
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);
    config()->set('doba.directory.enabled', true);

    $response = $this->getJson('/.well-known/doba.json')->assertOk();

    expect($response->json('room_types.0.kind'))->toBe('apartment')
        ->and($this->getJson('/api/directory/quote?'.http_build_query(['check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(3)->toDateString()]))->json('offers.0.cleaning_fee.amount'))->toBe(6000)
        ->and($response->json('room_types.0.bedrooms'))->toBe(2)
        ->and($response->json('room_types.0.min_nights'))->toBe(3)
        ->and($response->json('room_types.0.cleaning_fee.amount'))->toBe(6000);
});

it('lets the admin create an apartment', function (): void {
    $this->actingAs(User::factory()->create())->post('/admin/room-types', [
        'is_active' => '1',
        'kind' => 'apartment',
        'base_occupancy' => 2, 'max_occupancy' => 4, 'max_adults' => 4, 'max_children' => 2,
        'default_rate' => 15000, 'total_units' => 3,
        'bedrooms' => 2, 'bathrooms' => 1, 'cleaning_fee' => 5000, 'min_nights' => 2,
        'translations' => ['en' => ['name' => 'Garden flat']],
    ])->assertRedirect();

    $type = RoomType::sole();

    expect($type->isApartment())->toBeTrue()
        ->and($type->bedrooms)->toBe(2)
        ->and($type->cleaning_fee)->toBe(5000)
        ->and($type->minNights())->toBe(2);

    // Left out entirely, a type is a room with no fee and no minimum —
    // which is what every type that existed before this column was.
    $this->actingAs(User::factory()->create())->post('/admin/room-types', [
        'is_active' => '1',
        'base_occupancy' => 2, 'max_occupancy' => 2, 'max_adults' => 2, 'max_children' => 0,
        'default_rate' => 9000, 'total_units' => 1,
        'translations' => ['en' => ['name' => 'Plain double']],
    ])->assertRedirect();

    $room = RoomType::query()->where('code', 'PLAIN_DOUBLE')->sole();

    expect($room->isApartment())->toBeFalse()
        ->and($room->cleaning_fee)->toBe(0)
        ->and($room->minNights())->toBe(1);
});

it('has an install template of holiday apartments', function (): void {
    expect((new RoomBuilder)->fromTemplate('apartments'))->toBe(3);

    $types = RoomType::query()->get();

    expect($types->every(fn (RoomType $t): bool => $t->isApartment() && $t->cleaning_fee > 0 && $t->minNights() >= 2))->toBeTrue();
});
