<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Mail\BookingConfirmed;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function funnelRoom(string $code = 'DBL', array $overrides = []): RoomType
{
    $roomType = RoomType::create(array_merge([
        'code' => $code,
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'max_adults' => 2,
        'max_children' => 1,
        'default_rate' => 10000,
        'total_units' => 2,
    ], $overrides));

    $roomType->translations()->create([
        'locale' => 'en', 'slug' => strtolower($code).'-room', 'name' => $code.' room',
    ]);

    return $roomType;
}

function openNights(RoomType $roomType, CarbonImmutable $from, int $days): void
{
    foreach (range(0, $days) as $i) {
        Availability::updateOrCreate(
            ['room_type_id' => $roomType->id, 'date' => $from->addDays($i)->toDateString()],
            ['allotment' => $roomType->total_units]
        );
    }
}

/**
 * @return array<string,string|int>
 */
function stayQuery(CarbonImmutable $checkIn, int $nights = 2, int $adults = 2, int $children = 0): array
{
    return [
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays($nights)->toDateString(),
        'adults' => $adults,
        'children' => $children,
    ];
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    config()->set('doba.payment.gateway', 'manual');
    Mail::fake();

    $this->checkIn = CarbonImmutable::today()->addDays(14);
    $this->roomType = funnelRoom();
    openNights($this->roomType, $this->checkIn, 3);
});

it('walks the whole funnel from search to a confirmed booking', function (): void {
    // 1. Search
    $search = $this->get('/en/booking/search?'.http_build_query(stayQuery($this->checkIn)));

    $search->assertOk()
        ->assertSee('DBL room')
        ->assertSee('€200')                       // 2 nights × €100
        // The funnel must never be indexed: crawling it manufactures holds.
        ->assertSee('content="noindex, nofollow"', false);

    // 2. Checkout page
    $this->get('/en/booking/checkout?'.http_build_query(
        stayQuery($this->checkIn) + ['room_type' => $this->roomType->id]
    ))->assertOk()->assertSee('DBL room');

    // 3. Create the booking
    $response = $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna',
        'last_name' => 'Kowalska',
        'email' => 'anna@example.com',
        'guest_notes' => 'Arriving late.',
        'terms' => '1',
    ]);

    $booking = Booking::sole();
    $response->assertRedirect('/en/booking/pay/'.$booking->reference);

    expect($booking->guest_notes)->toBe('Arriving late.')
        ->and($booking->nights)->toBe(2)
        ->and($booking->total)->toBe(20000)
        // The manual gateway confirms immediately (§8).
        ->and($booking->status)->toBe(BookingStatus::Confirmed);

    // Inventory moved from held to booked.
    expect(Availability::query()->where('date', $this->checkIn->toDateString())->first())
        ->booked->toBe(1)
        ->held->toBe(0);

    Mail::assertQueued(BookingConfirmed::class, fn ($mail): bool => $mail->hasTo('anna@example.com'));

    // 4. Pay page redirects on: nothing left to pay
    $this->get('/en/booking/pay/'.$booking->reference)
        ->assertRedirect('/en/booking/confirmation/'.$booking->reference);

    // 5. Confirmation
    $this->get('/en/booking/confirmation/'.$booking->reference)
        ->assertOk()
        ->assertSee($booking->reference)
        ->assertSee(__('booking.confirmed_heading'));
});

it('reports each unusable date range instead of searching', function (string $query, string $key): void {
    $this->get('/en/booking/search?'.$query)->assertOk()->assertSee(__($key));
})->with([
    'reversed' => [fn () => http_build_query(['check_in' => CarbonImmutable::today()->addDays(5)->toDateString(), 'check_out' => CarbonImmutable::today()->addDays(2)->toDateString()]), 'booking.error_range'],
    'past' => [fn () => http_build_query(['check_in' => CarbonImmutable::today()->subDay()->toDateString(), 'check_out' => CarbonImmutable::today()->addDay()->toDateString()]), 'booking.error_past'],
    'too long' => [fn () => http_build_query(['check_in' => CarbonImmutable::today()->addDay()->toDateString(), 'check_out' => CarbonImmutable::today()->addDays(60)->toDateString()]), 'booking.error_too_long'],
]);

it('offers only rooms that fit the party', function (): void {
    $family = funnelRoom('FAM', ['max_occupancy' => 5, 'max_adults' => 4, 'max_children' => 2]);
    openNights($family, $this->checkIn, 3);

    // Four adults do not fit the 2-adult double.
    $this->get('/en/booking/search?'.http_build_query(stayQuery($this->checkIn, adults: 4)))
        ->assertOk()
        ->assertSee('FAM room')
        ->assertDontSee('DBL room');
});

it('shows nothing bookable when the nights are closed', function (): void {
    Availability::query()->update(['closed' => true]);

    $this->get('/en/booking/search?'.http_build_query(stayQuery($this->checkIn)))
        ->assertOk()
        ->assertSee(__('booking.no_offers'));
});

it('sends the guest back to search when the room went while they typed', function (): void {
    // Both units confirmed away between the search and the checkout load.
    Availability::query()->update(['booked' => 2]);

    $this->get('/en/booking/checkout?'.http_build_query(
        stayQuery($this->checkIn) + ['room_type' => $this->roomType->id]
    ))->assertRedirect()->assertSessionHas('booking_error', __('booking.error_gone'));
});

it('refuses a booking without consent to the terms', function (): void {
    $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna',
        'last_name' => 'Kowalska',
        'email' => 'anna@example.com',
    ])->assertSessionHasErrors('terms');

    expect(Booking::count())->toBe(0);
});

it('adds chosen extras to the booking total', function (): void {
    $breakfast = Extra::create([
        'code' => 'BREAKFAST', 'price' => 1800, 'applies_per' => 'person_night',
        'tax_rate' => 700, 'max_quantity' => 1, 'is_active' => true,
    ]);
    $breakfast->translations()->create(['locale' => 'en', 'name' => 'Breakfast']);

    $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna',
        'last_name' => 'Kowalska',
        'email' => 'anna@example.com',
        'extras' => [$breakfast->id => 1],
        'terms' => '1',
    ])->assertRedirect();

    // €200 room + (2 nights × 2 guests × €18) breakfast.
    expect(Booking::sole())
        ->extras_total->toBe(7200)
        ->total->toBe(27200);
});

it('holds inventory but does not confirm when payment is pending', function (): void {
    config()->set('doba.payment.gateway', 'stripe');
    config()->set('services.stripe.secret', 'sk_test');
    Http::fake([
        'api.stripe.com/*' => Http::response(['id' => 'pi_1', 'client_secret' => 'sec']),
    ]);

    $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'a@example.com', 'terms' => '1',
    ])->assertRedirect();

    $booking = Booking::sole();

    expect($booking->status)->toBe(BookingStatus::Pending);

    // Held, not booked — and the pay page shows rather than redirects.
    expect(Availability::query()->where('date', $this->checkIn->toDateString())->first())
        ->held->toBe(1)
        ->booked->toBe(0);

    $this->get('/en/booking/pay/'.$booking->reference)->assertOk()->assertSee(__('booking.pay_title'));

    // No confirmation mail until the webhook says so (§8).
    Mail::assertNotQueued(BookingConfirmed::class);
});

/*
|--------------------------------------------------------------------------
| The guest's self-service link (§11)
|--------------------------------------------------------------------------
*/

it('opens the manage page with the right token and 404s without it', function (): void {
    $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'a@example.com', 'terms' => '1',
    ]);

    $booking = Booking::sole();

    $this->get("/en/booking/manage/{$booking->reference}/{$booking->manage_token}")
        ->assertOk()
        ->assertSee($booking->reference);

    // A guessed reference is worthless without the 40-char token.
    $this->get("/en/booking/manage/{$booking->reference}/".str_repeat('x', 40))->assertNotFound();
});

it('lets the guest cancel their own booking and releases the room', function (): void {
    $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'a@example.com', 'terms' => '1',
    ]);

    $booking = Booking::sole();

    $this->post("/en/booking/manage/{$booking->reference}/{$booking->manage_token}/cancel")
        ->assertRedirect()
        ->assertSessionHas('booking_cancelled');

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);

    // The room is sellable again.
    expect(Availability::query()->where('date', $this->checkIn->toDateString())->first())
        ->booked->toBe(0)
        ->held->toBe(0);
});

it('refuses a cancellation with a forged token', function (): void {
    $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'a@example.com', 'terms' => '1',
    ]);

    $booking = Booking::sole();

    $this->post("/en/booking/manage/{$booking->reference}/".str_repeat('x', 40).'/cancel')
        ->assertNotFound();

    expect($booking->fresh()->status)->not->toBe(BookingStatus::Cancelled);
});

it('will not cancel a booking twice', function (): void {
    $this->post('/en/booking', stayQuery($this->checkIn) + [
        'room_type' => $this->roomType->id,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'a@example.com', 'terms' => '1',
    ]);

    $booking = Booking::sole();
    $url = "/en/booking/manage/{$booking->reference}/{$booking->manage_token}/cancel";

    $this->post($url)->assertRedirect();
    $this->post($url)->assertRedirect()->assertSessionHas('booking_error');

    // One cancellation in the audit trail, and held never went negative.
    expect($booking->statusHistory()->where('to_status', BookingStatus::Cancelled)->count())->toBe(1)
        ->and(Availability::query()->where('held', '<', 0)->count())->toBe(0);
});

it('serves the funnel in every locale on its translated path', function (): void {
    $this->roomType->translations()->create([
        'locale' => 'de', 'slug' => 'dbl-zimmer', 'name' => 'DBL Zimmer',
    ]);

    $this->get('/de/buchung/search?'.http_build_query(stayQuery($this->checkIn)))
        ->assertOk()
        ->assertSee('DBL Zimmer')
        ->assertSee(__('booking.search_title', [], 'de'));
});

it('advertises scarcity only when units have actually sold', function (): void {
    // Two units, none booked: a small hotel must not permanently claim
    // "only 2 left" — that is manufactured scarcity (§6).
    $this->get('/en/booking/search?'.http_build_query(stayQuery($this->checkIn)))
        ->assertOk()
        ->assertDontSee(__('booking.only_left', ['count' => 2]));

    // One unit genuinely taken: now the warning is true.
    Availability::query()
        ->whereBetween('date', [$this->checkIn->toDateString(), $this->checkIn->addDay()->toDateString()])
        ->update(['booked' => 1]);

    $this->get('/en/booking/search?'.http_build_query(stayQuery($this->checkIn)))
        ->assertOk()
        ->assertSee(__('booking.only_left', ['count' => 1]));
});
