<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Housekeeping's list (§12): dirty doors first, the ones with a guest
 * arriving today before the rest, one button each.
 */
function hkType(): RoomType
{
    return RoomType::query()->where('code', 'HK')->firstOr(function (): RoomType {
        $type = RoomType::create(['code' => 'HK', 'base_occupancy' => 2, 'max_occupancy' => 2, 'default_rate' => 10000, 'total_units' => 5]);
        $type->translations()->create(['locale' => 'en', 'slug' => 'hk', 'name' => 'Double']);

        foreach (range(-3, 10) as $i) {
            Availability::firstOrCreate(
                ['room_type_id' => $type->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
                ['allotment' => 5],
            );
        }

        return $type;
    });
}

function hkDoor(string $number, string $status = 'dirty', ?string $floor = null): Room
{
    return Room::create(['room_type_id' => hkType()->id, 'number' => $number, 'floor' => $floor, 'status' => $status]);
}

function hkStayInto(Room $room, CarbonImmutable $checkIn, int $nights, BookingStatus $status, ?string $arrivalTime = null, string $email = 'a@example.com'): Booking
{
    $booking = app(BookingService::class)->place(
        hkType(), $checkIn, $checkIn->addDays($nights),
        ['email' => $email, 'first_name' => 'A', 'last_name' => 'B'],
        adults: 2,
    );
    $booking = app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    if ($status === BookingStatus::CheckedIn) {
        $booking = app(BookingService::class)->transition($booking, BookingStatus::CheckedIn, 'test');
    }

    $booking->rooms()->update(['room_id' => $room->id]);
    $booking->forceFill(['arrival_time' => $arrivalTime])->save();

    return $booking->fresh();
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en']);
    $this->admin = User::factory()->create();
    $this->today = CarbonImmutable::today(config('doba.timezone'));
});

it('keeps the list behind the admin session', function (): void {
    $door = hkDoor('101');

    $this->get('/admin/housekeeping')->assertRedirect('/admin/login');
    $this->post('/admin/housekeeping/'.$door->id.'/clean')->assertRedirect('/admin/login');

    expect($door->fresh()->status)->toBe('dirty');
});

it('puts the doors with a guest arriving today first, earliest arrival on top', function (): void {
    $late = hkDoor('201');
    $early = hkDoor('202');
    $plain = hkDoor('203');
    hkDoor('204', 'clean');

    hkStayInto($late, $this->today, 2, BookingStatus::Confirmed, '16:00', 'late@example.com');
    hkStayInto($early, $this->today, 2, BookingStatus::Confirmed, '13:00', 'early@example.com');

    $response = $this->actingAs($this->admin)->get('/admin/housekeeping')->assertOk();

    $response->assertSeeInOrder(['First — a guest arrives today', '202', 'arriving 13:00', '201', 'arriving 16:00', 'To clean', '203'])
        ->assertSee('3 doors to clean')
        ->assertSee('2 with guests arriving today')
        // No guest names — housekeeping needs the door, not the person.
        ->assertDontSee('early@example.com')
        ->assertDontSee('B, A');
});

it('shows a door still occupied by somebody leaving today, without a clean button for it', function (): void {
    $door = hkDoor('301', 'clean');
    hkStayInto($door, $this->today->subDays(2), 2, BookingStatus::CheckedIn, null, 'leaving@example.com');

    $this->actingAs($this->admin)->get('/admin/housekeeping')
        ->assertOk()
        ->assertSee('Leaving today, still occupied')
        ->assertSee('301')
        ->assertSee('1 leaving today')
        ->assertDontSee('/admin/housekeeping/'.$door->id.'/clean');
});

it('marks a door clean with one tap, and only a dirty one', function (): void {
    $dirty = hkDoor('401');
    $broken = hkDoor('402', 'out_of_order');

    $this->actingAs($this->admin)->post('/admin/housekeeping/'.$dirty->id.'/clean')
        ->assertRedirect('/admin/housekeeping')
        ->assertSessionHas('saved');
    $this->actingAs($this->admin)->post('/admin/housekeeping/'.$broken->id.'/clean')->assertRedirect();

    expect($dirty->fresh()->status)->toBe('clean')
        // A door out of order has a bigger problem than the sheets.
        ->and($broken->fresh()->status)->toBe('out_of_order');

    $this->actingAs($this->admin)->post('/admin/housekeeping/'.$dirty->id.'/dirty')->assertRedirect();
    expect($dirty->fresh()->status)->toBe('dirty');
});

it('counts the dirty doors in the sidebar', function (): void {
    hkDoor('501');
    hkDoor('502');
    hkDoor('503', 'clean');

    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSeeInOrder(['Housekeeping', 'bg-amber-500', '>2<'], false);
});

it('points at the rooms page when no doors are listed', function (): void {
    $this->actingAs($this->admin)->get('/admin/housekeeping')
        ->assertOk()
        ->assertSee('No doors are listed yet');
});
