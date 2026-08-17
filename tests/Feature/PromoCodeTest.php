<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Booking\PromoCodeException;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\Guest;
use App\Models\PromoCode;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

function promo(array $attributes = []): PromoCode
{
    return PromoCode::create(array_merge([
        'code' => 'SPRING',
        'discount_type' => 'percent',
        'value' => 1000,
    ], $attributes));
}

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 10000, 'total_units' => 3,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(20);

    foreach (range(0, 5) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 3,
        ]);
    }

    $this->service = app(BookingService::class);

    $this->book = fn (?PromoCode $code = null, int $nights = 3, int $units = 1) => $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays($nights),
        ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2, units: $units, promoCode: $code,
    );
});

it('takes a percentage off in basis points, never in floats', function (): void {
    // 12.5% of €300.
    $booking = ($this->book)(promo(['value' => 1250]));

    expect($booking->subtotal)->toBe(30000)
        ->and($booking->discount_total)->toBe(3750)
        ->and($booking->total)->toBe(26250)
        ->and($booking->balance_due)->toBe(26250);
});

it('never discounts more than the stay is worth', function (): void {
    // A €500 code on a €300 stay is €300 off, not a €200 refund the hotel
    // suddenly owes.
    $booking = ($this->book)(promo(['discount_type' => 'fixed', 'value' => 50000]));

    expect($booking->discount_total)->toBe(30000)
        ->and($booking->total)->toBe(0);
});

it('gives away the cheapest nights first when the code is free-nights', function (): void {
    // Make the middle night dear and the last one cheap.
    Availability::query()
        ->where('date', $this->checkIn->addDay()->toDateString())
        ->update(['price' => 20000]);

    Availability::query()
        ->where('date', $this->checkIn->addDays(2)->toDateString())
        ->update(['price' => 6000]);

    $booking = ($this->book)(promo(['discount_type' => 'free_nights', 'value' => 1]));

    // €100 + €200 + €60; the free night is the €60 one, which is both what
    // the guest expects from "one night free" and what costs least.
    expect($booking->subtotal)->toBe(36000)
        ->and($booking->discount_total)->toBe(6000);
});

it('multiplies the discount across rooms, like the price it discounts', function (): void {
    $booking = ($this->book)(promo(['value' => 1000]), units: 2);

    expect($booking->subtotal)->toBe(60000)
        ->and($booking->discount_total)->toBe(6000);
});

it('explains why a code does not apply instead of just refusing it', function (): void {
    $guest = Guest::findOrCreateByEmail('anna@example.com', [
        'email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K',
    ]);
    $stay = fn (PromoCode $code, int $nights = 2, int $subtotal = 20000) => $code->rejectionReason(
        $this->checkIn, $nights, $subtotal, [$this->roomType->id], $guest,
    );

    expect($stay(promo(['min_nights' => 3])))->toBe('promo.error_min_nights')
        ->and($stay(promo(['code' => 'B', 'min_total' => 50000])))->toBe('promo.error_min_total')
        ->and($stay(promo(['code' => 'C', 'valid_to' => CarbonImmutable::yesterday()->toDateString()])))->toBe('promo.error_expired')
        ->and($stay(promo(['code' => 'D', 'valid_from' => CarbonImmutable::tomorrow()->toDateString()])))->toBe('promo.error_not_yet_valid')
        ->and($stay(promo(['code' => 'E', 'stay_to' => $this->checkIn->subDay()->toDateString()])))->toBe('promo.error_stay_window')
        ->and($stay(promo(['code' => 'F', 'room_type_ids' => [999]])))->toBe('promo.error_room_type')
        ->and($stay(promo(['code' => 'G', 'is_active' => false])))->toBe('promo.error_invalid')
        // A code that clears every hurdle says so by returning nothing.
        ->and($stay(promo(['code' => 'H'])))->toBeNull();
});

it('re-checks the code under lock at booking time, not only when it was typed', function (): void {
    $code = promo(['usage_limit' => 1]);

    ($this->book)($code);

    // The limit is reached between the guest typing the code and finishing
    // checkout — exactly the race the lock exists for.
    expect(fn () => ($this->book)($code->fresh()))
        ->toThrow(PromoCodeException::class);

    expect(PromoCode::sole()->usage_count)->toBe(1);
});

it('gives the use back when a booking is cancelled', function (): void {
    $code = promo(['usage_limit' => 1]);

    $booking = ($this->book)($code);

    expect($code->fresh()->usage_count)->toBe(1)
        ->and($code->fresh()->activeRedemptions())->toBe(1);

    $this->service->transition($booking, BookingStatus::Cancelled, 'Hold expired');

    // An abandoned checkout must not retire a campaign.
    expect($code->fresh()->usage_count)->toBe(0)
        ->and($code->fresh()->activeRedemptions())->toBe(0)
        // The row survives, so the campaign report can still explain it.
        ->and($code->fresh()->redemptions()->count())->toBe(1);

    $second = ($this->book)($code->fresh());

    expect($second->discount_total)->toBe(3000);
});

it('counts a per-guest limit against that guest only', function (): void {
    $code = promo(['per_guest_limit' => 1]);

    ($this->book)($code);

    $guest = Guest::query()->where('email', 'anna@example.com')->sole();
    $other = Guest::findOrCreateByEmail('bea@example.com', ['email' => 'bea@example.com', 'first_name' => 'Bea', 'last_name' => 'L']);

    expect($code->fresh()->rejectionReason($this->checkIn, 3, 30000, [$this->roomType->id], $guest))
        ->toBe('promo.error_guest_limit')
        ->and($code->fresh()->rejectionReason($this->checkIn, 3, 30000, [$this->roomType->id], $other))
        ->toBeNull();
});

it('discounts the stay but never the extras', function (): void {
    $booking = ($this->book)(promo(['value' => 1000]));

    $extra = Extra::create([
        'code' => 'TRANSFER', 'price' => 4500, 'applies_per' => 'stay',
        'tax_rate' => 1900, 'max_quantity' => 1, 'is_active' => true,
    ]);
    $extra->translations()->create(['locale' => 'en', 'name' => 'Transfer']);

    $this->service->addExtras($booking, [$extra->id => 1]);

    $booking = $booking->fresh();

    // €300 stay − €30 + €45 transfer. The transfer is bought at its listed
    // price; a stay discount that quietly took 10% off it is not what
    // either side agreed to.
    expect($booking->discount_total)->toBe(3000)
        ->and($booking->extras_total)->toBe(4500)
        ->and($booking->total)->toBe(31500);
});

it('puts the discount on the invoice as a negative line at the room rate', function (): void {
    $booking = ($this->book)(promo(['value' => 1000]));

    $this->service->transition($booking, BookingStatus::Confirmed);

    $invoice = $booking->fresh()->invoice;
    $discountLine = $invoice->lines->firstWhere('line_gross', '<', 0);

    expect($discountLine)->not->toBeNull()
        ->and($discountLine->line_gross)->toBe(-3000)
        // net + tax === gross holds for negative lines too, or the totals
        // stop reconciling the moment a code is used.
        ->and($discountLine->line_net + $discountLine->tax_amount)->toBe($discountLine->line_gross)
        ->and($invoice->gross_total)->toBe($booking->fresh()->total);
});

it('applies a code posted at checkout and shows what it took off', function (): void {
    promo(['code' => 'SPRING25', 'value' => 2500]);

    $payload = [
        'room_type' => $this->roomType->id,
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(3)->toDateString(),
        'adults' => 2, 'children' => 0,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
        'terms' => '1',
    ];

    // Lower-cased and padded, the way a guest actually pastes a code.
    $this->post('/en/booking', $payload + ['promo_code' => '  spring25 '])->assertRedirect();

    $booking = Booking::sole();

    expect($booking->discount_total)->toBe(7500)
        ->and($booking->total)->toBe(22500);

    $this->get("/en/booking/manage/{$booking->reference}/{$booking->manage_token}")
        ->assertOk()
        ->assertSee('SPRING25')
        ->assertSee(__('promo.discount'));
});

it('sends the guest back with the reason rather than silently ignoring a bad code', function (): void {
    promo(['code' => 'LONGSTAY', 'min_nights' => 7]);

    $response = $this->post('/en/booking', [
        'room_type' => $this->roomType->id,
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(3)->toDateString(),
        'adults' => 2, 'children' => 0,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
        'terms' => '1', 'promo_code' => 'LONGSTAY',
    ]);

    $response->assertRedirect()->assertSessionHas('booking_error', __('promo.error_min_nights'));

    // Nothing was taken and no inventory was held on a failed code.
    expect(Booking::query()->count())->toBe(0)
        ->and(Availability::query()->where('held', '>', 0)->count())->toBe(0);

    // And the form they filled in comes back with them.
    $this->followingRedirects()
        ->post('/en/booking', [
            'room_type' => $this->roomType->id,
            'check_in' => $this->checkIn->toDateString(),
            'check_out' => $this->checkIn->addDays(3)->toDateString(),
            'adults' => 2, 'children' => 0,
            'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
            'terms' => '1', 'promo_code' => 'LONGSTAY',
        ])
        ->assertOk()
        ->assertSee(__('promo.error_min_nights'));
});

it('stores a percentage from the admin form as basis points', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/admin/promo-codes', [
            // Lower case, as a hotelier types it.
            'code' => ' winter15 ',
            'discount_type' => 'percent',
            'value' => 15,
            'is_active' => '1',
        ])
        ->assertRedirect('/admin/promo-codes');

    $code = PromoCode::sole();

    expect($code->code)->toBe('WINTER15')
        // 15% arrives as 15 and is stored as 1500 basis points, so the
        // money path never sees a float.
        ->and($code->value)->toBe(1500)
        ->and($code->discountFor([10000, 10000]))->toBe(3000);
});

it('compares uniqueness against the code it will actually store', function (): void {
    promo(['code' => 'SPRING']);

    // Upper-casing after validation is how a case-different duplicate gets
    // past Rule::unique and 500s on the index instead.
    $this->actingAs(User::factory()->create())
        ->post('/admin/promo-codes', ['code' => 'spring', 'discount_type' => 'fixed', 'value' => 500])
        ->assertSessionHasErrors('code');

    expect(PromoCode::query()->count())->toBe(1);
});

it('deactivates a redeemed code instead of deleting its history', function (): void {
    $code = promo(['code' => 'USED']);
    ($this->book)($code);

    $admin = User::factory()->create();

    $this->actingAs($admin)->delete("/admin/promo-codes/{$code->id}")->assertRedirect('/admin/promo-codes');

    expect($code->fresh())->not->toBeNull()
        ->and($code->fresh()->is_active)->toBeFalse();

    // An unused code has no history worth keeping.
    $unused = promo(['code' => 'UNUSED']);
    $this->actingAs($admin)->delete("/admin/promo-codes/{$unused->id}");

    expect(PromoCode::query()->find($unused->id))->toBeNull();
});

it('reports what each campaign actually gave away', function (): void {
    $code = promo(['code' => 'REPORT', 'value' => 1000]);
    ($this->book)($code);

    $this->actingAs(User::factory()->create())
        ->get('/admin/promo-codes')
        ->assertOk()
        ->assertSee('REPORT')
        ->assertSee(__('admin.promo_given', ['amount' => Money::format(3000)]));
});
