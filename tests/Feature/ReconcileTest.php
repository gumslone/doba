<?php

declare(strict_types=1);

use App\Domain\Availability\Reconciler;
use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\ChannelBooking;
use App\Models\ChannelFeed;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 10000, 'total_units' => 4,
    ]);

    $this->checkIn = CarbonImmutable::today()->addDays(10);

    foreach (range(0, 6) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->checkIn->addDays($i)->toDateString(),
            'allotment' => 4,
        ]);
    }

    $this->service = app(BookingService::class);
    $this->reconciler = app(Reconciler::class);

    $this->book = fn (int $units = 1) => $this->service->place(
        $this->roomType, $this->checkIn, $this->checkIn->addDays(3),
        ['email' => 'anna@example.com', 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2, units: $units,
    );

    $this->row = fn (int $offset) => Availability::query()
        ->where('room_type_id', $this->roomType->id)
        ->where('date', $this->checkIn->addDays($offset)->toDateString())
        ->first();
});

it('finds nothing to report on a healthy install', function (): void {
    $booking = ($this->book)(2);
    $this->service->transition($booking, BookingStatus::Confirmed);
    ($this->book)(1);

    expect($this->reconciler->drift())->toBe([]);
});

it('detects a counter that has drifted low, which is an oversell in progress', function (): void {
    $booking = ($this->book)(2);
    $this->service->transition($booking, BookingStatus::Confirmed);

    // Two units are genuinely booked; the counter says one. The hotel is
    // selling a room it has already sold.
    ($this->row)(0)->forceFill(['booked' => 1])->save();

    $drift = $this->reconciler->drift();

    expect($drift)->toHaveCount(1)
        ->and($drift[0]['column'])->toBe('booked')
        ->and($drift[0]['counter'])->toBe(1)
        ->and($drift[0]['truth'])->toBe(2);

    $this->reconciler->fix($drift);

    expect(($this->row)(0)->booked)->toBe(2)
        ->and($this->reconciler->drift())->toBe([]);
});

it('detects a counter stuck high, the quiet failure nobody notices', function (): void {
    ($this->book)(1);
    $this->service->transition(Booking::sole(), BookingStatus::Confirmed);

    // Nothing is wrong that anyone can see: no guest is turned away, the
    // hotel simply stops selling a room it has. That is why this direction
    // is reported just as loudly as the other.
    ($this->row)(1)->forceFill(['booked' => 3])->save();

    $drift = $this->reconciler->drift();

    expect($drift)->toHaveCount(1)
        ->and($drift[0]['counter'])->toBe(3)
        ->and($drift[0]['truth'])->toBe(1);

    $this->reconciler->fix($drift);

    expect(($this->row)(1)->booked)->toBe(1);
});

it('counts a pending hold as held and a confirmed booking as booked', function (): void {
    ($this->book)(2);

    expect($this->reconciler->drift())->toBe([]);
    expect(($this->row)(0))->held->toBe(2)->booked->toBe(0);

    $this->service->transition(Booking::sole(), BookingStatus::Confirmed);

    expect($this->reconciler->drift())->toBe([])
        ->and(($this->row)(0))->held->toBe(0)->booked->toBe(2);
});

it('counts an OTA block as booked, because it occupies the room the same way', function (): void {
    $feed = ChannelFeed::create([
        'room_type_id' => $this->roomType->id,
        'channel' => 'booking_com',
        'name' => 'Booking.com',
    ]);

    ChannelBooking::create([
        'channel_feed_id' => $feed->id,
        'room_type_id' => $this->roomType->id,
        'external_uid' => 'a@ota',
        'check_in' => $this->checkIn->toDateString(),
        'check_out' => $this->checkIn->addDays(2)->toDateString(),
        'units' => 1,
    ]);

    // The channel sync increments the counter; the reconcile must agree,
    // or every OTA booking would be reported as drift every night.
    ($this->row)(0)->forceFill(['booked' => 1])->save();
    ($this->row)(1)->forceFill(['booked' => 1])->save();

    expect($this->reconciler->drift())->toBe([]);

    // Checkout night is not occupied.
    ($this->row)(2)->forceFill(['booked' => 1])->save();

    expect($this->reconciler->drift())->toHaveCount(1);
});

it('ignores a released hold and a cancelled booking', function (): void {
    $booking = ($this->book)(1);
    $this->service->transition($booking, BookingStatus::Cancelled, 'Hold expired');

    // Everything went back; anything still counted would be a leak.
    expect($this->reconciler->drift())->toBe([])
        ->and(($this->row)(0))->held->toBe(0)->booked->toBe(0);
});

it('raises the allotment rather than aborting when a hotel really is oversold', function (): void {
    $booking = ($this->book)(4);
    $this->service->transition($booking, BookingStatus::Confirmed);

    // Someone shrank the room type below what is already committed. The
    // CHECK constraint would refuse booked + held > allotment, and no
    // arithmetic here can un-sell those rooms.
    Availability::query()->where('room_type_id', $this->roomType->id)->update(['allotment' => 1, 'booked' => 0]);

    $drift = $this->reconciler->drift();

    expect($drift)->not->toBeEmpty();

    $this->reconciler->fix($drift);

    expect(($this->row)(0))->booked->toBe(4)->allotment->toBe(4)
        ->and($this->reconciler->drift())->toBe([]);
});

it('exits non-zero even after fixing, because drift means a bug already happened', function (): void {
    $booking = ($this->book)(1);
    $this->service->transition($booking, BookingStatus::Confirmed);
    ($this->row)(0)->forceFill(['booked' => 2])->save();

    expect(Artisan::call('availability:reconcile'))->toBe(1);
    expect(Artisan::output())->toContain('disagree');

    // Still non-zero: a reconcile that repairs drift and exits 0 is a bug
    // nobody ever hears about.
    expect(Artisan::call('availability:reconcile', ['--fix' => true]))->toBe(1);

    expect(Artisan::call('availability:reconcile'))->toBe(0)
        ->and(Artisan::output())->toContain('No drift');
});
