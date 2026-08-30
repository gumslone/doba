<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\FrontDesk\RoomAssignment;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Pinning a sold category to a door (§5, phase 2).
 *
 * The engine sells categories; these tests hold the seam where a
 * category becomes door 101 — and the one invariant that seam has: two
 * parties never hold the same door on the same night.
 */
function doorStay(CarbonImmutable $checkIn, int $nights = 2, string $email = 'anna@example.com'): Booking
{
    $roomType = RoomType::query()->firstOr(fn () => RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 2,
    ]));

    foreach (range(0, $nights + 6) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
            ['allotment' => 2],
        );
    }

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays($nights),
        ['email' => $email, 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2,
    );

    return app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test')->fresh();
}

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->today = CarbonImmutable::today(config('doba.timezone'));
});

it('refuses to give two stays the same door on the same night', function (): void {
    $first = doorStay($this->today->addDays(2));
    $overlapping = doorStay($this->today->addDays(3), email: 'b@example.com');

    $door = Room::create(['room_type_id' => RoomType::sole()->id, 'number' => '101']);

    app(RoomAssignment::class)->assign($first->rooms->first(), $door);

    expect(fn () => app(RoomAssignment::class)->assign($overlapping->rooms->first(), $door))
        ->toThrow(InvalidArgumentException::class, '101');
});

it('lets back-to-back stays share a door, because a night is half-open', function (): void {
    $leaving = doorStay($this->today->addDays(1), nights: 2);                          // out on day 3
    $arriving = doorStay($this->today->addDays(3), email: 'b@example.com');           // in on day 3

    $door = Room::create(['room_type_id' => RoomType::sole()->id, 'number' => '101']);

    app(RoomAssignment::class)->assign($leaving->rooms->first(), $door);

    // The stay that checks out on the morning this one checks in does
    // not collide — same rule the availability engine lives by (§6).
    app(RoomAssignment::class)->assign($arriving->rooms->first(), $door);

    expect($arriving->rooms->first()->fresh()->room_id)->toBe($door->id);
});

it('frees the door the moment its stay is cancelled or checked out', function (): void {
    $first = doorStay($this->today->addDays(2));
    $second = doorStay($this->today->addDays(2), email: 'b@example.com');

    $door = Room::create(['room_type_id' => RoomType::sole()->id, 'number' => '101']);
    app(RoomAssignment::class)->assign($first->rooms->first(), $door);

    app(BookingService::class)->transition($first, BookingStatus::Cancelled, 'test');

    // The guest is demonstrably not in it.
    app(RoomAssignment::class)->assign($second->rooms->first(), $door);

    expect($second->rooms->first()->fresh()->room_id)->toBe($door->id);
});

it('refuses a door of another category and one that is out of order', function (): void {
    $booking = doorStay($this->today->addDays(2));

    $otherType = RoomType::create([
        'code' => 'SUITE', 'base_occupancy' => 2, 'max_occupancy' => 4,
        'default_rate' => 20000, 'total_units' => 1,
    ]);

    $wrongDoor = Room::create(['room_type_id' => $otherType->id, 'number' => '501']);
    $brokenDoor = Room::create(['room_type_id' => RoomType::query()->where('code', 'DBL')->sole()->id, 'number' => '102', 'status' => 'out_of_order']);

    expect(fn () => app(RoomAssignment::class)->assign($booking->rooms->first(), $wrongDoor))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(RoomAssignment::class)->assign($booking->rooms->first(), $brokenDoor))
        ->toThrow(InvalidArgumentException::class);
});

it('offers only sensible doors, current choice included', function (): void {
    $booking = doorStay($this->today->addDays(2));
    $other = doorStay($this->today->addDays(2), email: 'b@example.com');

    $type = RoomType::sole()->id;
    $mine = Room::create(['room_type_id' => $type, 'number' => '101']);
    $theirs = Room::create(['room_type_id' => $type, 'number' => '102']);
    $broken = Room::create(['room_type_id' => $type, 'number' => '103', 'status' => 'out_of_order']);

    app(RoomAssignment::class)->assign($booking->rooms->first(), $mine);
    app(RoomAssignment::class)->assign($other->rooms->first(), $theirs);

    $options = app(RoomAssignment::class)->optionsFor($booking->rooms->first());

    // My own door stays in the list — a reassignment dropdown that hides
    // the current choice looks broken — the neighbour's and the broken
    // one do not.
    expect($options->pluck('number')->all())->toBe(['101']);
});

it('sends the door to housekeeping when the guest leaves', function (): void {
    $booking = doorStay($this->today);

    $door = Room::create(['room_type_id' => RoomType::sole()->id, 'number' => '101']);
    app(RoomAssignment::class)->assign($booking->rooms->first(), $door);

    app(BookingService::class)->transition($booking, BookingStatus::CheckedIn, 'test');
    app(BookingService::class)->transition($booking, BookingStatus::CheckedOut, 'test');

    // On the list the moment the guest leaves it.
    expect($door->fresh()->status)->toBe('dirty');
});

it('assigns from the front desk and says no politely when it must', function (): void {
    $booking = doorStay($this->today);
    $other = doorStay($this->today, email: 'b@example.com');

    $door = Room::create(['room_type_id' => RoomType::sole()->id, 'number' => '101']);

    $this->actingAs($this->admin)
        ->post('/admin/front-desk/'.$booking->id.'/assign-room', [
            'booking_room_id' => $booking->rooms->first()->id,
            'room_id' => $door->id,
        ])->assertSessionHas('saved');

    expect($booking->rooms->first()->fresh()->room_id)->toBe($door->id);

    // The same door for the overlapping stay is refused with the number
    // in the sentence, not a 500.
    $this->actingAs($this->admin)
        ->post('/admin/front-desk/'.$other->id.'/assign-room', [
            'booking_room_id' => $other->rooms->first()->id,
            'room_id' => $door->id,
        ])->assertSessionHas('desk_error');

    expect($other->rooms->first()->fresh()->room_id)->toBeNull();

    // And the desk sees who still has no door.
    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee('101')
        ->assertSee('no room yet');
});

it('cannot hand one booking a room slot belonging to another', function (): void {
    $booking = doorStay($this->today);
    $other = doorStay($this->today, email: 'b@example.com');

    $door = Room::create(['room_type_id' => RoomType::sole()->id, 'number' => '101']);

    // A forged booking_room_id from a different booking 404s: the route
    // scopes the slot to the booking in the URL.
    $this->actingAs($this->admin)
        ->post('/admin/front-desk/'.$booking->id.'/assign-room', [
            'booking_room_id' => $other->rooms->first()->id,
            'room_id' => $door->id,
        ])->assertNotFound();
});

it('keeps history when a door is removed, and manages the fleet', function (): void {
    $booking = doorStay($this->today->addDays(2));
    $door = Room::create(['room_type_id' => RoomType::sole()->id, 'number' => '101']);
    app(RoomAssignment::class)->assign($booking->rooms->first(), $door);

    // The admin page lists it, with the count-vs-units nudge.
    $this->actingAs($this->admin)->get('/admin/rooms')
        ->assertOk()->assertSee('101')->assertSee('differs from the 2');

    // Removing the door keeps the stay's records; the pin just opens.
    $this->actingAs($this->admin)->post('/admin/rooms/'.$door->id.'/delete')->assertRedirect('/admin/rooms');

    expect(Booking::sole()->rooms->first()->room_id)->toBeNull()
        ->and(Room::query()->count())->toBe(0);
});
