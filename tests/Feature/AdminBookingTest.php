<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Booking\NoAvailabilityException;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Bookings the desk makes and changes (§12).
 */
function deskRoom(int $units = 2): RoomType
{
    $roomType = RoomType::query()->firstOr(fn () => RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'max_adults' => 3, 'max_children' => 2,
        'default_rate' => 10000, 'total_units' => $units,
    ]));

    foreach (range(0, 30) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
            ['allotment' => $units],
        );
    }

    return $roomType;
}

function deskNight(string $date): Availability
{
    return Availability::query()->where('date', $date)->sole();
}

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->today = CarbonImmutable::today(config('doba.timezone'));
});

it('takes a phone booking at the desk, confirmed, through the same engine', function (): void {
    deskRoom();

    $this->actingAs($this->admin)->post('/admin/bookings', [
        'room_type_id' => RoomType::sole()->id,
        'check_in' => $this->today->addDays(3)->toDateString(),
        'check_out' => $this->today->addDays(5)->toDateString(),
        'adults' => 2,
        'first_name' => 'Anna', 'last_name' => 'Kowalska',
        'phone' => '+48 600 000 000',
        'source' => 'phone',
        'internal_notes' => 'Wants a quiet room.',
        'confirm' => '1',
    ])->assertRedirect();

    $booking = Booking::sole();

    // Booked, not held: a promise made on the phone is a promise.
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe('phone')
        ->and($booking->internal_notes)->toBe('Wants a quiet room.')
        ->and($booking->total)->toBe(20000)
        ->and(deskNight($this->today->addDays(3)->toDateString())->booked)->toBe(1)
        // No email given: one was minted that can never receive anything.
        ->and($booking->guest->email)->toEndWith('@no-email.invalid');
});

it('refuses a desk booking the calendar cannot take, saying which night', function (): void {
    deskRoom(units: 1);
    $room = RoomType::sole();

    // The one unit is sold for night +4.
    app(BookingService::class)->place($room, $this->today->addDays(4), $this->today->addDays(5),
        ['email' => 'x@example.com', 'first_name' => 'X', 'last_name' => 'Y'], adults: 2);

    $this->actingAs($this->admin)->post('/admin/bookings', [
        'room_type_id' => $room->id,
        'check_in' => $this->today->addDays(3)->toDateString(),
        'check_out' => $this->today->addDays(6)->toDateString(),
        'adults' => 2, 'first_name' => 'A', 'last_name' => 'B', 'source' => 'phone',
    ])->assertSessionHasErrors('check_in');

    expect(Booking::query()->count())->toBe(1);
});

it('moves a stay to new dates, releasing and taking nights under lock', function (): void {
    config()->set('doba.taxes.city_tax_per_person_night', 100);
    $room = deskRoom();

    $booking = app(BookingService::class)->place($room, $this->today->addDays(3), $this->today->addDays(5),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2);
    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    // Shift a day later and a night longer: +3..+5 becomes +4..+7.
    $moved = app(BookingService::class)->changeStay($booking, $this->today->addDays(4), $this->today->addDays(7));

    expect($moved->check_in->toDateString())->toBe($this->today->addDays(4)->toDateString())
        ->and($moved->nights)->toBe(3)
        // Night +3 released, +4 kept, +5 and +6 taken.
        ->and(deskNight($this->today->addDays(3)->toDateString())->booked)->toBe(0)
        ->and(deskNight($this->today->addDays(4)->toDateString())->booked)->toBe(1)
        ->and(deskNight($this->today->addDays(6)->toDateString())->booked)->toBe(1)
        // Re-priced for three nights, tax for two adults × three nights.
        ->and($moved->subtotal)->toBe(30000)
        ->and($moved->city_tax)->toBe(600)
        ->and($moved->total)->toBe(30600)
        ->and($moved->rooms->first()->nights()->count())->toBe(3);
});

it('changes nothing at all when a gained night is sold out', function (): void {
    $room = deskRoom(units: 1);

    $booking = app(BookingService::class)->place($room, $this->today->addDays(3), $this->today->addDays(5),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2);
    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    // Somebody else holds night +6.
    app(BookingService::class)->place($room, $this->today->addDays(6), $this->today->addDays(7),
        ['email' => 'b@example.com', 'first_name' => 'B', 'last_name' => 'C'], adults: 2);

    expect(fn () => app(BookingService::class)->changeStay($booking, $this->today->addDays(4), $this->today->addDays(7)))
        ->toThrow(NoAvailabilityException::class);

    // The transaction rolled everything back: the stay is exactly as it
    // was, night +3 still booked, nothing released halfway.
    $fresh = $booking->fresh();

    expect($fresh->check_in->toDateString())->toBe($this->today->addDays(3)->toDateString())
        ->and($fresh->total)->toBe(20000)
        ->and(deskNight($this->today->addDays(3)->toDateString())->booked)->toBe(1)
        ->and(deskNight($this->today->addDays(4)->toDateString())->booked)->toBe(1);
});

it('refuses to move a stay that has ended or was cancelled', function (): void {
    $room = deskRoom();

    $booking = app(BookingService::class)->place($room, $this->today->addDays(3), $this->today->addDays(5),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2);
    app(BookingService::class)->transition($booking, BookingStatus::Cancelled, 'test');

    expect(fn () => app(BookingService::class)->changeStay($booking, $this->today->addDays(4), $this->today->addDays(6)))
        ->toThrow(InvalidArgumentException::class);
});

it('edits from the desk page and keeps notes when only notes change', function (): void {
    $room = deskRoom();

    $booking = app(BookingService::class)->place($room, $this->today->addDays(3), $this->today->addDays(5),
        ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2);
    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    $this->actingAs($this->admin)->get('/admin/bookings/'.$booking->id.'/edit')->assertOk()->assertSee($booking->reference);

    $this->actingAs($this->admin)->put('/admin/bookings/'.$booking->id, [
        'check_in' => $this->today->addDays(3)->toDateString(),
        'check_out' => $this->today->addDays(5)->toDateString(),
        'adults' => 2, 'children' => 0,
        'arrival_time' => '18:30',
        'guest_notes' => 'Late arrival',
        'internal_notes' => 'VIP',
    ])->assertSessionHas('saved');

    $fresh = $booking->fresh();

    expect($fresh->arrival_time)->toBe('18:30')
        ->and($fresh->internal_notes)->toBe('VIP')
        ->and($fresh->total)->toBe(20000);
});

it('keeps desk bookings behind the admin session', function (): void {
    deskRoom();

    $this->get('/admin/bookings/create')->assertRedirect('/admin/login');
    $this->post('/admin/bookings', [])->assertRedirect('/admin/login');
});
