<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Reporting\Reports;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\ChannelBooking;
use App\Models\ChannelFeed;
use App\Models\Extra;
use App\Models\PromoCode;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 3,
        'default_rate' => 10000, 'total_units' => 2,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    // A ten-night window with two rooms: 20 sellable room-nights.
    $this->start = CarbonImmutable::today()->addDays(10);
    $this->end = $this->start->addDays(9);

    foreach (range(0, 12) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->start->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    $this->service = app(BookingService::class);
    $this->reports = app(Reports::class);

    $this->sell = function (int $offset, int $nights, int $units = 1, ?PromoCode $promo = null): Booking {
        $booking = $this->service->place(
            $this->roomType,
            $this->start->addDays($offset),
            $this->start->addDays($offset + $nights),
            ['email' => 'g'.$offset.'@example.com', 'first_name' => 'A', 'last_name' => 'B'],
            adults: 2, units: $units, promoCode: $promo,
        );

        $this->service->transition($booking, BookingStatus::Confirmed);

        return $booking->fresh();
    };
});

it('computes occupancy, ADR and RevPAR that agree with each other', function (): void {
    // 4 room-nights sold at €100 out of 20 available.
    ($this->sell)(0, 4);

    $summary = $this->reports->summary($this->start, $this->end);

    expect($summary['capacity'])->toBe(20)
        ->and($summary['room_nights_sold'])->toBe(4)
        ->and($summary['occupancy'])->toBe(0.2)
        ->and($summary['adr'])->toBe(10000)
        ->and($summary['room_revenue'])->toBe(40000)
        // RevPAR is occupancy × ADR, and must come out that way or one of
        // the three is wrong.
        ->and($summary['revpar'])->toBe((int) round(0.2 * 10000))
        ->and($summary['revpar'])->toBe((int) round(40000 / 20));
});

it('counts a cancelled booking as nothing at all', function (): void {
    $booking = ($this->sell)(0, 3);
    $this->service->transition($booking, BookingStatus::Cancelled);

    expect($this->reports->summary($this->start, $this->end))
        ->room_nights_sold->toBe(0)
        ->room_revenue->toBe(0)
        ->occupancy->toBe(0.0);
});

it('treats a no-show as a sold room-night', function (): void {
    $booking = ($this->sell)(0, 2);
    $this->service->transition($booking, BookingStatus::NoShow);

    // It consumed inventory: no other guest could have had that room.
    expect($this->reports->summary($this->start, $this->end)['room_nights_sold'])->toBe(2);
});

it('leaves extras out of the room metrics', function (): void {
    $booking = ($this->sell)(0, 2);

    $extra = Extra::create([
        'code' => 'BREAKFAST', 'price' => 2000, 'applies_per' => 'person_night',
        'tax_rate' => 700, 'max_quantity' => 2, 'is_active' => true,
    ]);
    $extra->translations()->create(['locale' => 'en', 'name' => 'Breakfast']);

    $this->service->addExtras($booking, [$extra->id => 1]);

    $summary = $this->reports->summary($this->start, $this->end);

    // €80 of breakfast is real money and belongs on the invoice — but ADR
    // is a room metric, and folding it in inflates the number a hotelier
    // compares against every benchmark they read.
    expect($booking->fresh()->total)->toBe(28000)
        ->and($summary['room_revenue'])->toBe(20000)
        ->and($summary['adr'])->toBe(10000);
});

it('takes the discount off the rate, not just off the invoice', function (): void {
    $promo = PromoCode::create(['code' => 'SPRING', 'discount_type' => 'percent', 'value' => 1000]);

    ($this->sell)(0, 4, promo: $promo);

    $summary = $this->reports->summary($this->start, $this->end);

    // €400 of nights, €40 given away. An ADR that ignores the discount
    // flatters the hotel by exactly what it handed over.
    expect($summary['room_revenue'])->toBe(36000)
        ->and($summary['adr'])->toBe(9000);
});

it('splits a discount across the months a stay straddles', function (): void {
    $promo = PromoCode::create(['code' => 'SPLIT', 'discount_type' => 'fixed', 'value' => 10000]);

    ($this->sell)(0, 4, promo: $promo);

    $firstHalf = $this->reports->roomRevenue($this->start, $this->start->addDay());
    $secondHalf = $this->reports->roomRevenue($this->start->addDays(2), $this->end);
    $whole = $this->reports->roomRevenue($this->start, $this->end);

    // Halves must add up to the whole, or a month-by-month report stops
    // summing to the year.
    expect($firstHalf + $secondHalf)->toBe($whole)
        ->and($whole)->toBe(40000 - 10000);
});

it('counts an OTA block as occupied but keeps it out of the rate', function (): void {
    ($this->sell)(0, 2);            // 2 nights at €100

    $feed = ChannelFeed::create([
        'room_type_id' => $this->roomType->id,
        'channel' => 'booking_com',
        'name' => 'Booking.com',
    ]);

    ChannelBooking::create([
        'channel_feed_id' => $feed->id,
        'room_type_id' => $this->roomType->id,
        'external_uid' => 'a@ota',
        'check_in' => $this->start->addDays(4)->toDateString(),
        'check_out' => $this->start->addDays(7)->toDateString(),
        'units' => 1,
    ]);

    $summary = $this->reports->summary($this->start, $this->end);

    expect($summary['ota_nights'])->toBe(3)
        ->and($summary['occupied_nights'])->toBe(5)
        // Occupancy counts the OTA nights: the rooms really were occupied.
        ->and($summary['occupancy'])->toBe(0.25)
        // ADR does not: averaging in three nights at zero would report a
        // rate the hotel never charged.
        ->and($summary['adr'])->toBe(10000)
        ->and($summary['room_nights_sold'])->toBe(2);
});

it('clips an OTA block to the reporting window', function (): void {
    $feed = ChannelFeed::create(['room_type_id' => $this->roomType->id, 'channel' => 'airbnb', 'name' => 'Airbnb']);

    ChannelBooking::create([
        'channel_feed_id' => $feed->id,
        'room_type_id' => $this->roomType->id,
        'external_uid' => 'long@ota',
        // Starts a week before the window and ends inside it.
        'check_in' => $this->start->subDays(7)->toDateString(),
        'check_out' => $this->start->addDays(2)->toDateString(),
        'units' => 1,
    ]);

    // Only the two nights that fall inside the range.
    expect($this->reports->otaNights($this->start, $this->end))->toBe(2);
});

it('excludes closed nights from what could have been sold', function (): void {
    Availability::query()
        ->whereIn('date', [$this->start->toDateString(), $this->start->addDay()->toDateString()])
        ->update(['closed' => true]);

    // A room out of order was never available to sell, and counting it
    // drags occupancy down for a decision nobody made about selling.
    expect($this->reports->capacity($this->start, $this->end))->toBe(16);
});

it('shows where the business came from, iCal blocks included', function (): void {
    ($this->sell)(0, 4);                                     // direct

    $phone = ($this->sell)(5, 2);
    $phone->forceFill(['source' => 'phone'])->save();

    $feed = ChannelFeed::create(['room_type_id' => $this->roomType->id, 'channel' => 'booking_com', 'name' => 'BC']);
    ChannelBooking::create([
        'channel_feed_id' => $feed->id,
        'room_type_id' => $this->roomType->id,
        'external_uid' => 'x@ota',
        'check_in' => $this->start->addDays(8)->toDateString(),
        'check_out' => $this->start->addDays(10)->toDateString(),
        'units' => 1,
    ]);

    $mix = collect($this->reports->channelMix($this->start, $this->end))->keyBy('source');

    expect($mix['direct']['nights'])->toBe(4)
        ->and($mix['phone']['nights'])->toBe(2)
        // Leaving OTA blocks out would make direct look like a larger
        // share of the hotel than it is — the exact number the commission
        // argument turns on.
        ->and($mix['ical']['nights'])->toBe(2)
        ->and($mix['ical']['revenue'])->toBe(0)
        ->and(round($mix['direct']['share'], 3))->toBe(0.5);
});

it('compares pace at the same point in the booking curve', function (): void {
    $lastYear = $this->start->subYear();

    foreach (range(0, 4) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $lastYear->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    // Last year: two nights, booked a year and a day ago.
    $old = $this->service->place(
        $this->roomType, $lastYear, $lastYear->addDays(2),
        ['email' => 'old@example.com', 'first_name' => 'A', 'last_name' => 'B'], adults: 2,
    );
    $this->service->transition($old, BookingStatus::Confirmed);
    Booking::query()->whereKey($old->id)->update(['created_at' => CarbonImmutable::today()->subYear()->subDays(3)]);

    // This year: four nights, booked today.
    ($this->sell)(0, 4);

    $pace = $this->reports->pace($this->start, $this->end);

    expect($pace['now']['nights'])->toBe(4)
        ->and($pace['last_year']['nights'])->toBe(2)
        ->and($pace['nights_change'])->toBe(1.0);
});

it('says nothing rather than "up 100%" when last year was empty', function (): void {
    ($this->sell)(0, 2);

    // "Up 100%" from nothing reads like growth and means "we had none".
    expect($this->reports->pace($this->start, $this->end)['nights_change'])->toBeNull();
});

it('adds up: the months sum to the whole period', function (): void {
    ($this->sell)(0, 5);

    $whole = $this->reports->summary($this->start, $this->end);
    $months = $this->reports->byMonth($this->start, $this->end);

    expect(array_sum(array_column($months, 'room_nights_sold')))->toBe($whole['room_nights_sold'])
        ->and(array_sum(array_column($months, 'room_revenue')))->toBe($whole['room_revenue'])
        ->and(array_sum(array_column($months, 'capacity')))->toBe($whole['capacity']);
});

it('serves the report and a CSV to an admin only', function (): void {
    ($this->sell)(0, 3);

    $this->get('/admin/reports')->assertRedirect('/admin/login');
    $this->get('/admin/reports/export')->assertRedirect('/admin/login');

    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get('/admin/reports?from='.$this->start->toDateString().'&to='.$this->end->toDateString())
        ->assertOk()
        ->assertSee(__('admin.occupancy'))
        ->assertSee(__('admin.revpar'));

    $csv = $this->actingAs($admin)
        ->get('/admin/reports/export?from='.$this->start->toDateString().'&to='.$this->end->toDateString())
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->streamedContent();

    expect($csv)->toContain('month,capacity,room_nights_sold')
        ->toContain($this->start->format('Y-m'));
});

it('reads an inverted date range as the typo it is', function (): void {
    ($this->sell)(0, 2);

    // Swapped, not answered with negative nights.
    $this->actingAs(User::factory()->create())
        ->get('/admin/reports?from='.$this->end->toDateString().'&to='.$this->start->toDateString())
        ->assertOk()
        ->assertSee(__('admin.occupancy'));
});
