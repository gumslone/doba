<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Guests\GuestPrivacy;
use App\Enums\BookingStatus;
use App\Models\ApiClient;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\PromoCode;
use App\Models\RoomType;
use Carbon\CarbonImmutable;

/**
 * The returning-guest discount (§7, phase 7).
 *
 * The loyalty scheme a small hotel can actually run: no points, no card.
 * Stayed before, booked direct again with the same email — a percentage
 * off, automatically, named on the invoice.
 */
function loyaltyRoom(): RoomType
{
    $roomType = RoomType::query()->firstOr(fn () => RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 5,
    ]));

    foreach (range(0, 40) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
            ['allotment' => 5],
        );
    }

    return $roomType;
}

/** A completed, confirmed stay in the past for this email. */
function priorStay(string $email): void
{
    $booking = app(BookingService::class)->place(
        loyaltyRoom(),
        CarbonImmutable::today(config('doba.timezone'))->addDays(30),
        CarbonImmutable::today(config('doba.timezone'))->addDays(32),
        ['email' => $email, 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2,
    );

    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');
}

function directBooking(string $email, ?PromoCode $promo = null, bool $direct = true): Booking
{
    return app(BookingService::class)->place(
        loyaltyRoom(),
        CarbonImmutable::today(config('doba.timezone'))->addDays(10),
        CarbonImmutable::today(config('doba.timezone'))->addDays(12),   // 2 nights = 20000
        ['email' => $email, 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2,
        promoCode: $promo,
        applyLoyalty: $direct,
    );
}

beforeEach(function (): void {
    config()->set('doba.loyalty.discount_bps', 500);   // 5%
    config()->set('doba.loyalty.min_stays', 1);
});

it('takes a percentage off for a guest who has stayed before', function (): void {
    priorStay('anna@example.com');

    $booking = directBooking('anna@example.com');

    // 5% of 20000, and every downstream total already sums discount_total.
    expect($booking->loyalty_discount)->toBe(1000)
        ->and($booking->discount_total)->toBe(1000)
        ->and($booking->total)->toBe(19000)
        ->and($booking->balance_due)->toBe(19000);
});

it('gives a first-time guest nothing, even mid-checkout of their first stay', function (): void {
    // stays_count moves on CONFIRMATION, so at placement it is exactly
    // the stays completed before this one: a pending first booking has
    // not made anyone a regular.
    $booking = directBooking('new@example.com');

    expect($booking->loyalty_discount)->toBe(0)
        ->and($booking->total)->toBe(20000);
});

it('honours the minimum-stays threshold', function (): void {
    config()->set('doba.loyalty.min_stays', 2);

    priorStay('anna@example.com');

    expect(directBooking('anna@example.com')->loyalty_discount)->toBe(0);

    priorStay('anna@example.com');   // now two behind them

    expect(directBooking('anna@example.com')->loyalty_discount)->toBe(1000);
});

it('never stacks on a promo code — the guest chose that offer', function (): void {
    priorStay('anna@example.com');

    $promo = PromoCode::create([
        'code' => 'SPRING10', 'discount_type' => 'percent', 'value' => 1000,
    ]);

    $booking = directBooking('anna@example.com', $promo);

    expect($booking->promo_code_id)->toBe($promo->id)
        ->and($booking->discount_total)->toBe(2000)    // the 10% code
        ->and($booking->loyalty_discount)->toBe(0);    // not 10% + 5%
});

it('applies only on the hotel\'s own site, never through a channel', function (): void {
    priorStay('anna@example.com');

    // The same guest, the same dates, via a partner: full price. A
    // channel manager is not the hotel's own front door.
    expect(directBooking('anna@example.com', direct: false)->loyalty_discount)->toBe(0);

    ['client' => $client, 'secret' => $secret] = ApiClient::issue('CM', ApiClient::SCOPES);

    $this->postJson('/api/v1/bookings', [
        'room_type' => 'DBL',
        'check_in' => CarbonImmutable::today(config('doba.timezone'))->addDays(20)->toDateString(),
        'check_out' => CarbonImmutable::today(config('doba.timezone'))->addDays(22)->toDateString(),
        'adults' => 2,
        'guest' => ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
    ], ['X-Api-Key-Id' => $client->key_id, 'X-Api-Secret' => $secret, 'Idempotency-Key' => 'k1'])
        ->assertStatus(201)
        ->assertJsonPath('data.discount_total.amount', 0);
});

it('names the discount for what earned it, on the invoice and the summary', function (): void {
    config()->set('doba.locales', ['en']);
    priorStay('anna@example.com');

    $booking = directBooking('anna@example.com');
    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    $invoice = $booking->fresh()->invoice;

    expect($invoice->lines->pluck('description')->all())->toContain('Returning-guest discount')
        ->and($invoice->gross_total)->toBe(19000);

    $this->get("/en/booking/manage/{$booking->reference}/{$booking->manage_token}")
        ->assertOk()->assertSee('Returning-guest discount');
});

it('is silent when switched off, and ignores an erased guest', function (): void {
    priorStay('anna@example.com');

    config()->set('doba.loyalty.discount_bps', 0);
    expect(directBooking('anna@example.com')->loyalty_discount)->toBe(0);

    config()->set('doba.loyalty.discount_bps', 500);

    // Erasure rightly refuses a guest with stays ahead of them, so the
    // history has to be behind them first.
    Booking::query()->update([
        'status' => BookingStatus::CheckedOut,
        'check_in' => CarbonImmutable::today(config('doba.timezone'))->subDays(5)->toDateString(),
        'check_out' => CarbonImmutable::today(config('doba.timezone'))->subDays(3)->toDateString(),
    ]);

    app(GuestPrivacy::class)->erase(Guest::query()->where('email', 'anna@example.com')->sole());

    // Their email is gone with them, so a new booking is a new guest.
    expect(directBooking('anna@example.com')->loyalty_discount)->toBe(0);
});

it('tells a guest at checkout which email earns it', function (): void {
    config()->set('doba.locales', ['en']);
    $room = loyaltyRoom();
    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    $this->get('/en/booking/checkout?'.http_build_query([
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 0, 'room_type' => $room->id,
    ]))->assertOk()->assertSee('5% returning-guest discount');
});
