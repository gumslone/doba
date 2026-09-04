<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Payments\GatewayRegistry;
use App\Enums\BookingStatus;
use App\Models\ApiClient;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\PromoCode;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

/**
 * The feature flags, honoured (§3).
 *
 * Four of them — FEATURE_PAYMENT, FEATURE_DEPOSIT_ONLY, FEATURE_PROMO,
 * FEATURE_EXTRAS — sat in config/doba.php and .env.example from phase 1
 * and were read by nothing. A switch that is documented and dead is
 * worse than no switch: a hotelier flips it, sees no change, and
 * concludes the software is broken rather than the flag.
 */
function flagRoom(): RoomType
{
    $roomType = RoomType::query()->firstOr(fn () => RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 5,
    ]));

    foreach (range(0, 20) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
            ['allotment' => 5],
        );
    }

    return $roomType;
}

function flagStay(array $stayOverrides = []): array
{
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    return array_merge([
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 0,
        'room_type' => flagRoom()->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
        'terms' => '1',
    ], $stayOverrides);
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en']);
    config()->set('doba.payment.gateway', 'stripe');
    config()->set('services.stripe.secret', 'sk_test');
});

it('takes bookings without online payment when FEATURE_PAYMENT is off', function (): void {
    config()->set('doba.features.online_payment', false);

    // Stripe stays configured; the checkout simply does not use it.
    expect(GatewayRegistry::default()->name())->toBe('manual');

    $this->post('/en/booking', flagStay());

    // Confirmed on the spot, as the manual gateway does — no intent was
    // ever created at Stripe.
    expect(Booking::sole()->status)->toBe(BookingStatus::Confirmed)
        ->and(Booking::sole()->payments()->where('gateway', 'stripe')->count())->toBe(0);
});

it('collects a share of the room price with FEATURE_DEPOSIT_ONLY, and everything without it', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 250);   // 2 adults × 2 nights = 1000

    $room = flagRoom();
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);
    $place = fn () => app(BookingService::class)->place(
        $room, $checkIn, $checkIn->addDays(2),
        ['email' => uniqid().'@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2,
    );

    // Default: the whole room price, exactly what was collected before the
    // flag was ever read — and never the city tax, which is settled with
    // the stay.
    config()->set('doba.features.deposit_only', true);
    config()->set('doba.payment.deposit_bps', 10000);
    expect($place()->deposit_due)->toBe(20000);

    // 30% deposit.
    config()->set('doba.payment.deposit_bps', 3000);
    expect($place()->deposit_due)->toBe(6000);

    // Off: everything now, tax included.
    config()->set('doba.features.deposit_only', false);
    expect($place()->deposit_due)->toBe(21000);
});

it('offers no extras and attaches none when FEATURE_EXTRAS is off', function (): void {
    $room = flagRoom();
    $extra = Extra::create(['code' => 'BRK', 'price' => 1500, 'charge_type' => 'per_stay', 'tax_rate' => 1000]);
    $extra->roomTypes()->attach($room->id);

    config()->set('doba.features.extras', false);

    $stay = flagStay();

    $this->get('/en/booking/checkout?'.http_build_query(array_intersect_key($stay, array_flip(['check_in', 'check_out', 'adults', 'children', 'room_type']))))
        ->assertOk()->assertDontSee('Add extras');

    // A stale form that still posts one is ignored, not honoured.
    $this->post('/en/booking', $stay + ['extras' => [$extra->id => 1]]);

    expect(Booking::sole()->extras()->count())->toBe(0)
        ->and(Booking::sole()->total)->toBe(20000);
});

it('hides the promo field, ignores stale codes and tells partners why, when FEATURE_PROMO is off', function (): void {
    PromoCode::create(['code' => 'SPRING10', 'discount_type' => 'percent', 'value' => 1000]);

    config()->set('doba.features.promo_codes', false);

    $stay = flagStay();

    $this->get('/en/booking/checkout?'.http_build_query(array_intersect_key($stay, array_flip(['check_in', 'check_out', 'adults', 'children', 'room_type']))))
        ->assertOk()->assertDontSee('Promo code');

    // The site: a code that arrives anyway is ignored, full price charged.
    $this->post('/en/booking', $stay + ['promo_code' => 'SPRING10']);
    expect(Booking::sole()->discount_total)->toBe(0);

    // The API: said, not swallowed — a partner passing a valid-looking
    // code should learn the hotel does not run them.
    ['client' => $client, 'secret' => $secret] = ApiClient::issue('CM', ApiClient::SCOPES);

    $this->postJson('/api/v1/bookings', [
        'room_type' => 'DBL',
        'check_in' => $stay['check_in'], 'check_out' => $stay['check_out'], 'adults' => 2,
        'promo_code' => 'SPRING10',
        'guest' => ['email' => 'b@example.com', 'first_name' => 'B', 'last_name' => 'C'],
    ], ['X-Api-Key-Id' => $client->key_id, 'X-Api-Secret' => $secret, 'Idempotency-Key' => 'k1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.promo_code.0', 'This hotel does not run promo codes.');
});

it('still shows the returning-guest hint with promo codes off, because that is a different thing', function (): void {
    config()->set('doba.features.promo_codes', false);
    config()->set('doba.loyalty.discount_bps', 500);

    $stay = flagStay();

    $this->get('/en/booking/checkout?'.http_build_query(array_intersect_key($stay, array_flip(['check_in', 'check_out', 'adults', 'children', 'room_type']))))
        ->assertOk()->assertDontSee('Promo code')->assertSee('returning-guest discount');
});
