<?php

declare(strict_types=1);

use App\Models\Availability;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use App\Models\User;
use Carbon\CarbonImmutable;

function gridRoom(string $code, int $units = 4, ?int $rate = 10000): RoomType
{
    $roomType = RoomType::create([
        'code' => $code,
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => $rate,
        'total_units' => $units,
    ]);

    $roomType->translations()->create(['locale' => 'en', 'slug' => strtolower($code), 'name' => $code.' room']);

    return $roomType;
}

function gridNights(RoomType $roomType, CarbonImmutable $from, int $days): void
{
    foreach (range(0, $days) as $i) {
        Availability::updateOrCreate(
            ['room_type_id' => $roomType->id, 'date' => $from->addDays($i)->toDateString()],
            ['allotment' => $roomType->total_units]
        );
    }
}

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->month = CarbonImmutable::today()->startOfMonth();
    $this->roomType = gridRoom('DBL');
    gridNights($this->roomType, $this->month, 40);
});

it('locks the grid behind admin login', function (): void {
    $this->get('/admin/availability')->assertRedirect('/admin/login');
    $this->put('/admin/availability')->assertRedirect('/admin/login');
});

it('renders a month of cells with resolved prices and what is sold', function (): void {
    // A season rate the grid must reflect: the hotelier needs to see the
    // price a guest is quoted, not only the manual overrides (§7).
    Season::create([
        'name' => 'Peak', 'priority' => 5,
        'starts_on' => $this->month->toDateString(),
        'ends_on' => $this->month->endOfMonth()->toDateString(),
    ])->rates()->create([
        'room_type_id' => $this->roomType->id,
        'weekday_mask' => SeasonRate::ALL_WEEK,
        'price' => 15000,
    ]);

    Availability::query()
        ->where('date', $this->month->addDays(3)->toDateString())
        ->update(['booked' => 1, 'held' => 1]);

    $html = $this->actingAs($this->admin)
        ->get('/admin/availability?month='.$this->month->format('Y-m'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('€150')       // season rate, not the €100 default
        ->toContain('2/4')                  // 4 units less 1 booked and 1 held
        ->toContain('DBL room');
});

it('sets a price across a date range', function (): void {
    $this->actingAs($this->admin)->put('/admin/availability', [
        'room_type_ids' => [$this->roomType->id],
        'from' => $this->month->toDateString(),
        'to' => $this->month->addDays(2)->toDateString(),
        'price' => '149.50',
    ])->assertRedirect();

    // Entered in euros, stored in cents (§5).
    expect(Availability::query()->where('date', $this->month->toDateString())->first()->price)->toBe(14950)
        ->and(Availability::query()->where('date', $this->month->addDays(2)->toDateString())->first()->price)->toBe(14950)
        // Outside the range, untouched.
        ->and(Availability::query()->where('date', $this->month->addDays(3)->toDateString())->first()->price)->toBeNull();
});

it('applies only to the ticked weekdays', function (): void {
    $saturday = $this->month->next(CarbonImmutable::SATURDAY);

    $this->actingAs($this->admin)->put('/admin/availability', [
        'room_type_ids' => [$this->roomType->id],
        'from' => $this->month->toDateString(),
        'to' => $this->month->addDays(20)->toDateString(),
        'weekdays' => [6], // Saturday, ISO
        'price' => '180',
        'min_stay' => 3,
    ])->assertRedirect();

    $priced = Availability::query()->whereNotNull('price')->get();

    // "Saturdays in July, min-stay 3" is one operation, not thirty-one.
    expect($priced)->not->toBeEmpty()
        ->and($priced->every(fn (Availability $row): bool => $row->date->isSaturday()))->toBeTrue()
        ->and(Availability::query()->where('date', $saturday->toDateString())->first())
        ->price->toBe(18000)
        ->min_stay->toBe(3);
});

it('leaves untouched every field the form did not fill in', function (): void {
    $date = $this->month->addDays(5)->toDateString();

    Availability::query()->where('date', $date)->update(['min_stay' => 4, 'closed_to_arrival' => true]);

    $this->actingAs($this->admin)->put('/admin/availability', [
        'room_type_ids' => [$this->roomType->id],
        'from' => $date,
        'to' => $date,
        'price' => '99',
    ])->assertRedirect();

    // Setting a price must not silently reset everyone's min-stay to 1.
    expect(Availability::query()->where('date', $date)->first())
        ->price->toBe(9900)
        ->min_stay->toBe(4)
        ->closed_to_arrival->toBeTrue();
});

it('sets stop-sell and the arrival/departure restrictions', function (): void {
    $date = $this->month->addDays(7)->toDateString();

    $this->actingAs($this->admin)->put('/admin/availability', [
        'room_type_ids' => [$this->roomType->id],
        'from' => $date,
        'to' => $date,
        'closed' => '1',
        'closed_to_arrival' => '1',
        'closed_to_departure' => '0',
    ])->assertRedirect();

    expect(Availability::query()->where('date', $date)->first())
        ->closed->toBeTrue()
        ->closed_to_arrival->toBeTrue()
        ->closed_to_departure->toBeFalse();
});

it('refuses to cut the allotment below what is already sold, and says which night', function (): void {
    $sold = $this->month->addDays(2)->toDateString();

    Availability::query()->where('date', $sold)->update(['booked' => 3]);

    $this->actingAs($this->admin)->put('/admin/availability', [
        'room_type_ids' => [$this->roomType->id],
        'from' => $this->month->toDateString(),
        'to' => $this->month->addDays(4)->toDateString(),
        'allotment' => 1,
    ])->assertRedirect()->assertSessionHas('saved');

    // The sold night keeps its allotment — the CHECK constraint would have
    // refused the write anyway, but the hotelier is told which date.
    expect(Availability::query()->where('date', $sold)->first()->allotment)->toBe(4)
        // Its neighbours were still updated.
        ->and(Availability::query()->where('date', $this->month->toDateString())->first()->allotment)->toBe(1);

    expect(session('saved'))->toContain($sold);
});

it('touches only the ticked room types', function (): void {
    $other = gridRoom('SGL');
    gridNights($other, $this->month, 10);

    $this->actingAs($this->admin)->put('/admin/availability', [
        'room_type_ids' => [$this->roomType->id],
        'from' => $this->month->toDateString(),
        'to' => $this->month->addDays(3)->toDateString(),
        'price' => '111',
    ])->assertRedirect();

    expect(Availability::query()->where('room_type_id', $this->roomType->id)->whereNotNull('price')->count())->toBe(4)
        ->and(Availability::query()->where('room_type_id', $other->id)->whereNotNull('price')->count())->toBe(0);
});

it('validates the range and rejects a reversed one', function (): void {
    $this->actingAs($this->admin)->put('/admin/availability', [
        'room_type_ids' => [$this->roomType->id],
        'from' => $this->month->addDays(5)->toDateString(),
        'to' => $this->month->toDateString(),
        'price' => '100',
    ])->assertSessionHasErrors('to');

    $this->actingAs($this->admin)->put('/admin/availability', [
        'from' => $this->month->toDateString(),
        'to' => $this->month->addDay()->toDateString(),
    ])->assertSessionHasErrors('room_type_ids');
});

it('marks dates past the generated horizon as unsellable rather than blank', function (): void {
    // Only 41 nights exist; a month beyond that has no rows at all, and §5
    // says a missing row is an error rather than "assume available".
    $html = $this->actingAs($this->admin)
        ->get('/admin/availability?month='.$this->month->addMonths(4)->format('Y-m'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('—')
        ->toContain(__('admin.grid_horizon', ['date' => Availability::query()->max('date')]));
});

it('shows the month the query asks for', function (): void {
    $target = $this->month->addMonth();

    $this->actingAs($this->admin)
        ->get('/admin/availability?month='.$target->format('Y-m'))
        ->assertOk()
        ->assertSee($target->translatedFormat('F Y'));
});
