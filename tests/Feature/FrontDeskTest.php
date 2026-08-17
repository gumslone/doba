<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config()->set('doba.checkin_from', '15:00');
    config()->set('doba.checkout_until', '11:00');

    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 10000, 'total_units' => 4,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    $this->today = CarbonImmutable::today();

    foreach (range(-2, 6) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->today->addDays($i)->toDateString(),
            'allotment' => 4,
        ]);
    }

    $this->service = app(BookingService::class);
    $this->admin = User::factory()->create();

    $this->stay = function (int $startsIn, int $nights, string $surname = 'Kowalska'): Booking {
        return $this->service->place(
            $this->roomType,
            $this->today->addDays($startsIn),
            $this->today->addDays($startsIn + $nights),
            ['email' => strtolower($surname).'@example.com', 'first_name' => 'Anna', 'last_name' => $surname],
            adults: 2,
        );
    };
});

it('shows who is arriving, with the time the guest gave', function (): void {
    $booking = ($this->stay)(0, 2);
    $booking->forceFill(['arrival_time' => '22:30'])->save();
    $this->service->transition($booking->fresh(), BookingStatus::Confirmed);

    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee('Kowalska')
        // The difference between holding a room and wondering at nine
        // whether to resell it.
        ->assertSee(__('admin.arriving_at', ['time' => '22:30']))
        ->assertDontSee(__('admin.no_arrival_time'));
});

it('says plainly when no arrival time was given', function (): void {
    $this->service->transition(($this->stay)(0, 2), BookingStatus::Confirmed);

    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee(__('admin.no_arrival_time'));
});

it('sorts arrivals by the stated time, with unknowns last', function (): void {
    foreach ([['Early', '15:00'], ['Late', '23:00'], ['Unknown', null]] as [$name, $time]) {
        $booking = ($this->stay)(0, 2, $name);
        $booking->forceFill(['arrival_time' => $time])->save();
        $this->service->transition($booking->fresh(), BookingStatus::Confirmed);
    }

    $html = $this->actingAs($this->admin)->get('/admin/front-desk')->getContent();

    // An unknown arrival is not an early one, so it sorts last rather
    // than to the top of the morning's list.
    expect(strpos($html, 'Early'))->toBeLessThan(strpos($html, 'Late'))
        ->and(strpos($html, 'Late'))->toBeLessThan(strpos($html, 'Unknown'));
});

it('moves a guest from arriving to in-house and then to gone', function (): void {
    $booking = ($this->stay)(0, 1);
    $this->service->transition($booking, BookingStatus::Confirmed);

    $this->actingAs($this->admin)->post("/admin/front-desk/{$booking->id}/check-in")->assertRedirect();

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::CheckedIn)
        ->and($booking->checked_in_at)->not->toBeNull();

    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee(__('admin.arrived_at', ['time' => $booking->checked_in_at->translatedFormat('j M, H:i')]));

    $this->actingAs($this->admin)->post("/admin/front-desk/{$booking->id}/check-out")->assertRedirect();

    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::CheckedOut)
        ->and($booking->checked_out_at)->not->toBeNull();

    // The room is free to clean and resell, and says so.
    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee(__('admin.rooms_free'));
});

it('counts a stay that began days ago as occupied today', function (): void {
    $booking = ($this->stay)(-2, 4);
    $this->service->transition($booking, BookingStatus::Confirmed);
    $this->service->transition($booking->fresh(), BookingStatus::CheckedIn);

    // An occupied room is occupied whether or not the guest arrived this
    // morning.
    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee('Kowalska')
        ->assertDontSee(__('admin.nobody_in_house'));
});

it('refuses an impossible move with a reason rather than an error', function (): void {
    $booking = ($this->stay)(0, 1);

    // Still pending: it cannot be checked in, and the desk should be told
    // so rather than shown a 500.
    $this->actingAs($this->admin)
        ->post("/admin/front-desk/{$booking->id}/check-in")
        ->assertRedirect()
        ->assertSessionHas('desk_error');

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('records a late checkout as a request, never as an answer', function (): void {
    $booking = ($this->stay)(0, 1);
    $this->service->transition($booking, BookingStatus::Confirmed);

    $this->post("/en/booking/manage/{$booking->reference}/{$booking->manage_token}/late-checkout", [
        'requested_checkout_time' => '14:00',
    ])->assertRedirect();

    $booking->refresh();

    // Asking is not being told yes: the room may be sold to somebody
    // arriving at three.
    expect($booking->requested_checkout_time)->toBe('14:00')
        ->and($booking->checkout_time)->toBeNull()
        ->and($booking->departureTime())->toBe('11:00')
        ->and($booking->hasLateCheckout())->toBeFalse()
        ->and($booking->hasPendingCheckoutRequest())->toBeTrue();

    $this->get("/en/booking/manage/{$booking->reference}/{$booking->manage_token}")
        ->assertOk()
        ->assertSee(__('booking.late_checkout_pending', ['time' => '14:00']));
});

it('lets the desk grant or decline a late checkout', function (): void {
    $booking = ($this->stay)(0, 1);
    $this->service->transition($booking, BookingStatus::Confirmed);
    $this->service->transition($booking->fresh(), BookingStatus::CheckedIn);
    $booking->forceFill(['requested_checkout_time' => '14:00'])->save();

    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee(__('admin.late_checkout_requested', ['time' => '14:00']));

    $this->actingAs($this->admin)
        ->post("/admin/front-desk/{$booking->id}/departure-time", ['decision' => 'grant', 'checkout_time' => '14:00'])
        ->assertRedirect();

    $booking->refresh();

    expect($booking->checkout_time)->toBe('14:00')
        ->and($booking->departureTime())->toBe('14:00')
        ->and($booking->hasLateCheckout())->toBeTrue()
        // The request is answered, so the desk stops being asked.
        ->and($booking->hasPendingCheckoutRequest())->toBeFalse();

    // Declining leaves the house time standing.
    $booking->forceFill(['requested_checkout_time' => '16:00', 'checkout_time' => null])->save();

    $this->actingAs($this->admin)
        ->post("/admin/front-desk/{$booking->id}/departure-time", ['decision' => 'decline'])
        ->assertRedirect();

    expect($booking->fresh())
        ->requested_checkout_time->toBeNull()
        ->checkout_time->toBeNull()
        ->and($booking->fresh()->departureTime())->toBe('11:00');
});

it('rejects a "later" checkout that is not later', function (): void {
    $booking = ($this->stay)(0, 1);
    $this->service->transition($booking, BookingStatus::Confirmed);

    $this->post("/en/booking/manage/{$booking->reference}/{$booking->manage_token}/late-checkout", [
        'requested_checkout_time' => '09:00',
    ])->assertSessionHas('booking_error', __('booking.late_checkout_not_later'));

    expect($booking->fresh()->requested_checkout_time)->toBeNull();
});

it('will not take a late-checkout request for a stay that is over', function (): void {
    $booking = ($this->stay)(0, 1);
    $this->service->transition($booking, BookingStatus::Cancelled);

    $this->post("/en/booking/manage/{$booking->reference}/{$booking->manage_token}/late-checkout", [
        'requested_checkout_time' => '14:00',
    ])->assertSessionHas('booking_error', __('booking.error_not_changeable'));

    expect($booking->fresh()->requested_checkout_time)->toBeNull();
});

it('captures the arrival time the guest chose at checkout', function (): void {
    $this->post('/en/booking', [
        'room_type' => $this->roomType->id,
        'check_in' => $this->today->addDays(3)->toDateString(),
        'check_out' => $this->today->addDays(5)->toDateString(),
        'adults' => 2, 'children' => 0,
        'first_name' => 'Anna', 'last_name' => 'K', 'email' => 'anna@example.com',
        'terms' => '1', 'arrival_time' => '21:30',
    ])->assertRedirect();

    expect(Booking::sole()->arrival_time)->toBe('21:30');
});

it('keeps the front desk behind the admin session', function (): void {
    $booking = ($this->stay)(0, 1);

    $this->get('/admin/front-desk')->assertRedirect('/admin/login');
    $this->post("/admin/front-desk/{$booking->id}/check-in")->assertRedirect('/admin/login');
    $this->post("/admin/front-desk/{$booking->id}/departure-time", ['decision' => 'grant'])
        ->assertRedirect('/admin/login');
});
