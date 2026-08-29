<?php

declare(strict_types=1);

use App\Domain\Availability\AvailabilityService;
use App\Domain\Booking\BookingService;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\RatePlan;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

function makePlan(string $code, array $attributes = [], array $translations = []): RatePlan
{
    $plan = RatePlan::create(array_merge([
        'code' => $code,
        'type' => 'standard',
        'adjustment_type' => 'percent',
        'adjustment_value' => 0,
        'refundable' => true,
        'cancellation_hours' => 48,
        'is_active' => true,
    ], $attributes));

    foreach ($translations ?: ['en' => ['Flexible', 'Cancel free up to 48h.']] as $locale => $t) {
        $plan->translations()->create([
            'locale' => $locale,
            'name' => $t[0],
            'policy_text' => $t[1],
        ]);
    }

    return $plan;
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);

    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 3,
    ]);
    $this->roomType->translations()->create(['locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room']);

    $this->checkIn = CarbonImmutable::today()->addDays(14);

    foreach (range(0, 8) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    $this->service = app(AvailabilityService::class);
});

it('adjusts the nightly price by percent in basis points', function (): void {
    // −12% of €100 is €88, and basis points keep a 7.5% rate off floats.
    expect(makePlan('SAVER', ['adjustment_value' => -1200])->adjust(10000))->toBe(8800)
        ->and(makePlan('PLUS', ['adjustment_value' => 1550])->adjust(10000))->toBe(11550);
});

it('adjusts by a fixed amount and never goes negative', function (): void {
    expect(makePlan('OFF', ['adjustment_type' => 'fixed', 'adjustment_value' => -1500])->adjust(10000))->toBe(8500)
        // A misconfigured plan must not produce a negative charge.
        ->and(makePlan('BROKEN', ['adjustment_type' => 'fixed', 'adjustment_value' => -50000])->adjust(10000))->toBe(0);
});

it('applies the adjustment per night, not to the total', function (): void {
    $plan = makePlan('SAVER', ['adjustment_value' => -1000]);

    // 3 nights × (€100 − 10%) = €270, and each frozen night is €90.
    $prices = $this->service->nightlyPrices($this->roomType, $this->checkIn, $this->checkIn->addDays(3), $plan);

    expect(array_values($prices))->toBe([9000, 9000, 9000])
        ->and($this->service->stayPrice($this->roomType, $this->checkIn, $this->checkIn->addDays(3), $plan))->toBe(27000);
});

it('honours every eligibility bound inclusively', function (): void {
    $longStay = makePlan('LONG', ['min_nights' => 5]);
    $early = makePlan('EARLY', ['min_days_before_arrival' => 30]);
    $lastMinute = makePlan('LATE', ['max_days_before_arrival' => 3]);

    expect($longStay->isEligible($this->checkIn, 4))->toBeFalse()
        ->and($longStay->isEligible($this->checkIn, 5))->toBeTrue()   // inclusive
        // Booked 14 days out: too late for a 30-day early bird…
        ->and($early->isEligible($this->checkIn, 2))->toBeFalse()
        // …but eligible exactly 30 days out, not 31. Counted from the
        // HOTEL's today, as isEligible itself counts: from 22:00 UTC a
        // Berlin hotel is already on tomorrow's date, and a UTC-framed
        // "exactly 30" here failed every summer evening between 22:00
        // and midnight — in CI, on whichever runs drew that window.
        ->and($early->isEligible(CarbonImmutable::today(config('doba.timezone'))->addDays(30), 2))->toBeTrue()
        ->and($lastMinute->isEligible($this->checkIn, 2))->toBeFalse()
        ->and($lastMinute->isEligible(CarbonImmutable::today(config('doba.timezone'))->addDays(3), 2))->toBeTrue();
});

it('bounds the validity window by the stay, not the booking date', function (): void {
    $summer = makePlan('SUMMER', [
        'valid_from' => $this->checkIn->addDays(2)->toDateString(),
        'valid_to' => $this->checkIn->addDays(4)->toDateString(),
    ]);

    expect($summer->isEligible($this->checkIn, 2))->toBeFalse()
        ->and($summer->isEligible($this->checkIn->addDays(3), 2))->toBeTrue();
});

it('offers only eligible plans, cheapest first', function (): void {
    makePlan('FLEX', ['adjustment_value' => 0, 'priority' => 10]);
    makePlan('SAVER', ['adjustment_value' => -1200]);
    makePlan('LONG', ['adjustment_value' => -3000, 'min_nights' => 5]);   // not for 2 nights
    makePlan('OLD', ['adjustment_value' => -9000, 'is_active' => false]); // inactive

    $offers = $this->service->ratePlansFor($this->roomType, $this->checkIn, $this->checkIn->addDays(2));

    expect(collect($offers)->pluck('plan.code')->all())->toBe(['SAVER', 'FLEX'])
        ->and($offers[0]['total'])->toBe(17600)   // 2 × €88
        ->and($offers[1]['total'])->toBe(20000);
});

it('offers house-wide plans everywhere and scoped ones only where attached', function (): void {
    $houseWide = makePlan('FLEX');
    $suiteOnly = makePlan('SUITE');

    $other = RoomType::create([
        'code' => 'SGL', 'base_occupancy' => 1, 'max_occupancy' => 1,
        'default_rate' => 8000, 'total_units' => 1,
    ]);

    $suiteOnly->roomTypes()->attach($this->roomType);

    $codes = fn (RoomType $rt): array => collect($this->service->ratePlansFor($rt, $this->checkIn, $this->checkIn->addDays(2)))
        ->pluck('plan.code')->all();

    expect($codes($this->roomType))->toEqualCanonicalizing(['FLEX', 'SUITE'])
        ->and($codes($other))->toBe(['FLEX']);
});

/*
|--------------------------------------------------------------------------
| The snapshot — what a dispute is settled by (§7)
|--------------------------------------------------------------------------
*/

it('freezes the policy wording onto the booking in the guest booking language', function (): void {
    $plan = makePlan('SAVER', ['adjustment_value' => -1200, 'refundable' => false, 'cancellation_hours' => 0], [
        'en' => ['Saver', 'This rate is not refundable.'],
        'de' => ['Sparrate', 'Diese Rate ist nicht erstattbar.'],
    ]);

    $booking = app(BookingService::class)->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2, locale: 'de', ratePlan: $plan,
    );

    $room = $booking->rooms->first();

    expect($room->rate_plan_id)->toBe($plan->id)
        // The wording they actually read, not today's version of it.
        ->and($room->cancellation_policy_snapshot)->toBe('Diese Rate ist nicht erstattbar.')
        ->and($room->refundable_snapshot)->toBeFalse()
        ->and($room->cancellation_hours_snapshot)->toBe(0)
        // The plan's discount reached the frozen per-night rows.
        ->and($booking->subtotal)->toBe(17600);

    // Editing the plan afterwards must not touch the taken booking.
    $plan->translations()->where('locale', 'de')->update(['policy_text' => 'Neue Bedingungen.']);
    $plan->update(['refundable' => true]);

    expect($room->fresh())
        ->cancellation_policy_snapshot->toBe('Diese Rate ist nicht erstattbar.')
        ->refundable_snapshot->toBeFalse();
});

it('computes the refund from the snapshot, not the live plan', function (): void {
    $flexible = makePlan('FLEX', ['cancellation_hours' => 48]);
    $service = app(BookingService::class);

    $booking = $service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2, ratePlan: $flexible,
    );

    $booking->forceFill(['paid_amount' => 20000])->save();

    // Well inside the free window.
    expect($service->refundableAmount($booking, CarbonImmutable::today()))->toBe(20000);

    // Past it — the deadline is 48h before the arrival day.
    expect($service->refundableAmount($booking, $this->checkIn->subHours(12)))->toBe(0);

    // The plan turning non-refundable later cannot claw back what the
    // guest already agreed to.
    $flexible->update(['refundable' => false, 'cancellation_hours' => 0]);

    expect($service->refundableAmount($booking->fresh(), CarbonImmutable::today()))->toBe(20000);
});

it('refunds nothing on a non-refundable rate, whenever it is cancelled', function (): void {
    $saver = makePlan('SAVER', ['refundable' => false, 'cancellation_hours' => 0]);
    $service = app(BookingService::class);

    $booking = $service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2, ratePlan: $saver,
    );

    $booking->forceFill(['paid_amount' => 20000])->save();

    expect($service->refundableAmount($booking, CarbonImmutable::today()))->toBe(0);
});

it('never refunds more than the guest actually paid', function (): void {
    $service = app(BookingService::class);

    $booking = $service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2, ratePlan: makePlan('FLEX'),
    );

    // Only the deposit was taken.
    $booking->forceFill(['paid_amount' => 5000])->save();

    expect($service->refundableAmount($booking, CarbonImmutable::today()))->toBe(5000);
});

it('still books with no plans configured at all', function (): void {
    $booking = app(BookingService::class)->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2,
    );

    // A hotel that never defines a plan sells at the base price and its
    // bookings are refundable by default.
    expect($booking->subtotal)->toBe(20000)
        ->and($booking->rooms->first())
        ->rate_plan_id->toBeNull()
        ->refundable_snapshot->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Through the funnel
|--------------------------------------------------------------------------
*/

it('books the plan the guest chose and refuses one the engine would not sell', function (): void {
    $flex = makePlan('FLEX');
    $long = makePlan('LONG', ['adjustment_value' => -5000, 'min_nights' => 7]);

    $payload = [
        'room_type' => $this->roomType->id,
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 0,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
        'terms' => '1',
    ];

    // The long-stay plan needs 7 nights; posting it for 2 must not buy a
    // 50% discount the engine would never have offered.
    $this->post('/en/booking', $payload + ['rate_plan' => $long->id])->assertRedirect();

    expect(Booking::sole())
        ->subtotal->toBe(20000)
        ->and(Booking::sole()->rooms->first()->rate_plan_id)->toBeNull();

    // The invoice goes first: the schema deliberately refuses to delete a
    // booking that has one, so an issued tax document cannot be destroyed
    // as a side effect of tidying up a stay.
    Invoice::query()->delete();
    Booking::query()->delete();

    $this->post('/en/booking', $payload + ['rate_plan' => $flex->id])->assertRedirect();

    expect(Booking::sole()->rooms->first()->rate_plan_id)->toBe($flex->id);
});

it('shows the rates on the room page and the checkout', function (): void {
    makePlan('FLEX', ['priority' => 10], ['en' => ['Flexible rate', 'Free until 48h.']]);
    makePlan('SAVER', ['adjustment_value' => -1200, 'refundable' => false], ['en' => ['Saver rate', 'Not refundable.']]);

    $this->get('/en/rooms/double-room')
        ->assertOk()
        ->assertSee('Flexible rate')
        ->assertSee('Saver rate')
        ->assertSee(__('booking.non_refundable'));

    $this->get('/en/booking/checkout?'.http_build_query([
        'room_type' => $this->roomType->id,
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 0,
    ]))->assertOk()->assertSee(__('booking.choose_rate'));
});
