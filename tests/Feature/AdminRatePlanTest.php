<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\AdjustmentType;
use App\Models\Availability;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);

    $this->admin = User::factory()->create();

    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 2,
    ]);
    $this->roomType->translations()->create(['locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room']);
});

function planPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'saver',
        'type' => 'non_refundable',
        'adjustment_type' => 'percent',
        'adjustment_value' => '-12',
        'cancellation_hours' => 0,
        'is_active' => '1',
        'translations' => [
            'en' => ['name' => 'Saver rate', 'policy_text' => 'This rate is not refundable.'],
            'de' => ['name' => 'Sparrate', 'policy_text' => 'Diese Rate ist nicht erstattbar.'],
        ],
    ], $overrides);
}

it('locks rate plan management behind admin login', function (): void {
    $this->get('/admin/rate-plans')->assertRedirect('/admin/login');
    $this->post('/admin/rate-plans')->assertRedirect('/admin/login');
});

it('creates a plan, converting the human percentage to basis points', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload())->assertRedirect();

    $plan = RatePlan::sole();

    expect($plan->code)->toBe('SAVER')                       // upper-cased
        ->and($plan->adjustment_type)->toBe(AdjustmentType::Percent)
        // "-12" in the form becomes -1200 basis points…
        ->and($plan->adjustment_value)->toBe(-1200)
        // …and prices a €100 night at €88.
        ->and($plan->adjust(10000))->toBe(8800)
        ->and($plan->refundable)->toBeFalse()                 // checkbox absent
        ->and($plan->t('name', 'de'))->toBe('Sparrate');
});

it('keeps a fractional percentage exact', function (): void {
    // 7.5% has no exact float, which is why the column is basis points.
    $this->actingAs($this->admin)
        ->post('/admin/rate-plans', planPayload(['adjustment_value' => '7.5']))
        ->assertRedirect();

    expect(RatePlan::sole())
        ->adjustment_value->toBe(750)
        ->and(RatePlan::sole()->adjust(10000))->toBe(10750);
});

it('stores a fixed adjustment in minor units', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload([
        'adjustment_type' => 'fixed',
        'adjustment_value' => '-15.50',
    ]))->assertRedirect();

    expect(RatePlan::sole())
        ->adjustment_value->toBe(-1550)
        ->and(RatePlan::sole()->adjust(10000))->toBe(8450);
});

it('round-trips the stored value back into the form unchanged', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload(['adjustment_value' => '-12.25']))->assertRedirect();

    $plan = RatePlan::sole();

    // The edit form must show "-12.25", not "-1225" — otherwise saving
    // twice would multiply the discount by a hundred each time.
    $this->actingAs($this->admin)
        ->get('/admin/rate-plans/'.$plan->id.'/edit')
        ->assertOk()
        ->assertSee('value="-12.25"', false);
});

it('saves eligibility bounds and validates their order', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload([
        'code' => 'longstay',
        'min_nights' => 5,
        'max_nights' => 21,
        'min_days_before_arrival' => 30,
        'valid_from' => '2027-06-01',
        'valid_to' => '2027-08-31',
    ]))->assertRedirect();

    expect(RatePlan::sole())
        ->min_nights->toBe(5)
        ->max_nights->toBe(21)
        ->min_days_before_arrival->toBe(30)
        ->and(RatePlan::sole()->valid_from->toDateString())->toBe('2027-06-01');

    // A max below the min, or a window that ends before it starts, is a
    // configuration mistake that would silently offer the plan to nobody.
    $this->actingAs($this->admin)
        ->post('/admin/rate-plans', planPayload(['code' => 'bad', 'min_nights' => 5, 'max_nights' => 2]))
        ->assertSessionHasErrors('max_nights');

    $this->actingAs($this->admin)
        ->post('/admin/rate-plans', planPayload(['code' => 'bad2', 'valid_from' => '2027-08-01', 'valid_to' => '2027-06-01']))
        ->assertSessionHasErrors('valid_to');
});

it('scopes a plan to room types, empty meaning house-wide', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/rate-plans', planPayload(['room_type_ids' => [$this->roomType->id]]))
        ->assertRedirect();

    expect(RatePlan::sole()->roomTypes)->toHaveCount(1);

    $this->actingAs($this->admin)
        ->post('/admin/rate-plans', planPayload(['code' => 'flex']))
        ->assertRedirect();

    expect(RatePlan::query()->where('code', 'FLEX')->sole()->roomTypes)->toHaveCount(0);
});

it('rejects a duplicate code', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload())->assertRedirect();

    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload())
        ->assertSessionHasErrors('code');

    expect(RatePlan::count())->toBe(1);
});

it('drops a language when its name is cleared', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload())->assertRedirect();
    $plan = RatePlan::sole();

    $this->actingAs($this->admin)->put('/admin/rate-plans/'.$plan->id, planPayload([
        'translations' => [
            'en' => ['name' => 'Saver rate', 'policy_text' => 'Not refundable.'],
            'de' => ['name' => ''],
        ],
    ]))->assertRedirect();

    expect($plan->fresh()->load('translations')->t('name', 'de', false))->toBeNull();
});

it('deactivates rather than deletes a plan that has been sold', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload())->assertRedirect();
    $plan = RatePlan::sole();

    $checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 2) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    app(BookingService::class)->place(
        $this->roomType, $checkIn, $checkIn->addDays(2),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2, ratePlan: $plan,
    );

    $this->actingAs($this->admin)->delete('/admin/rate-plans/'.$plan->id)->assertRedirect();

    // booking_rooms still points at it — staff must be able to see which
    // rate a stay was sold on.
    expect($plan->fresh())->not->toBeNull()
        ->and($plan->fresh()->is_active)->toBeFalse();
});

it('deletes a plan that was never sold', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload())->assertRedirect();

    $this->actingAs($this->admin)->delete('/admin/rate-plans/'.RatePlan::sole()->id)->assertRedirect();

    expect(RatePlan::count())->toBe(0);
});

it('makes a newly created plan sellable straight away', function (): void {
    $this->actingAs($this->admin)->post('/admin/rate-plans', planPayload(['code' => 'flex', 'adjustment_value' => '0']))->assertRedirect();

    $checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 3) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    $this->get('/en/booking/checkout?'.http_build_query([
        'room_type' => $this->roomType->id,
        'check_in' => $checkIn->toDateString(),
        'check_out' => $checkIn->addDays(2)->toDateString(),
        'adults' => 2, 'children' => 0,
    ]))->assertOk()->assertSee('Saver rate');
});
