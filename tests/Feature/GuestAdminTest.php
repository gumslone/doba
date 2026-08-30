<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Guests\GuestPrivacy;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/**
 * Who actually stays here — and how they are let go of (§12, §14).
 */
function guestStay(string $email, int $daysAgo = 0, string $status = 'confirmed'): Booking
{
    $roomType = RoomType::create([
        'code' => 'DBL-'.uniqid(),
        'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 5,
    ]);

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(14);

    foreach (range(0, 3) as $i) {
        Availability::create([
            'room_type_id' => $roomType->id,
            'date' => $checkIn->addDays($i)->toDateString(),
            'allotment' => 5,
        ]);
    }

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => $email, 'first_name' => 'Anna', 'last_name' => 'Kowalska', 'phone' => '+48 600 000 000'],
        adults: 2,
    );

    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    if ($daysAgo > 0) {
        $booking->forceFill([
            'status' => $status,
            'check_in' => CarbonImmutable::today(config('doba.timezone'))->subDays($daysAgo + 2)->toDateString(),
            'check_out' => CarbonImmutable::today(config('doba.timezone'))->subDays($daysAgo)->toDateString(),
        ])->save();
    }

    return $booking->fresh();
}

beforeEach(function (): void {
    $this->admin = User::factory()->create();
});

it('keeps the guest book behind the admin session', function (): void {
    guestStay('anna@example.com');
    $guest = Guest::sole();

    $this->get('/admin/guests')->assertRedirect('/admin/login');
    $this->get('/admin/guests/'.$guest->id.'/export')->assertRedirect('/admin/login');
    $this->post('/admin/guests/'.$guest->id.'/erase', ['confirm' => 'ERASE'])->assertRedirect('/admin/login');
});

it('builds one profile per address and shows the history on it', function (): void {
    guestStay('anna@example.com');
    guestStay('ANNA@example.com', daysAgo: 30, status: 'checked_out');

    $guest = Guest::sole();   // deduplicated by lowercased email

    expect($guest->stays_count)->toBe(2)
        ->and($guest->total_spent)->toBe(40000);

    $this->actingAs($this->admin)->get('/admin/guests')
        ->assertOk()
        ->assertSee('Kowalska')
        ->assertSee('returning');

    $this->actingAs($this->admin)->get('/admin/guests/'.$guest->id)
        ->assertOk()
        ->assertSee('2nd stay')
        ->assertSeeInOrder([Booking::query()->orderByDesc('check_in')->first()->reference]);
});

it('finds a guest by half their name or address', function (): void {
    guestStay('anna@example.com');
    guestStay('bob@elsewhere.net');
    Guest::query()->where('email', 'bob@elsewhere.net')->update(['first_name' => 'Bob', 'last_name' => 'Novak']);

    $this->actingAs($this->admin)->get('/admin/guests?q=kowal')
        ->assertOk()->assertSee('anna@example.com')->assertDontSee('bob@elsewhere.net');

    $this->actingAs($this->admin)->get('/admin/guests?q=elsewhere')
        ->assertOk()->assertSee('bob@elsewhere.net');
});

it('lets staff withdraw marketing consent but never grant it', function (): void {
    guestStay('anna@example.com');
    $guest = tap(Guest::sole(), fn (Guest $g) => $g->forceFill(['marketing_consent' => false])->save());

    // Ticking the box for a guest who never consented changes nothing:
    // consent is the guest's own checkbox at checkout, or it is nothing.
    $this->actingAs($this->admin)->post('/admin/guests/'.$guest->id, ['marketing_consent' => '1']);
    expect($guest->fresh()->marketing_consent)->toBeFalse();

    // The phone call "stop emailing me", though, is one click.
    $guest->forceFill(['marketing_consent' => true])->save();
    $this->actingAs($this->admin)->post('/admin/guests/'.$guest->id, []);
    expect($guest->fresh()->marketing_consent)->toBeFalse();
});

it('exports everything held about a person, as a file', function (): void {
    $booking = guestStay('anna@example.com');
    $booking->forceFill(['guest_notes' => 'Allergic to feathers'])->save();

    $response = $this->actingAs($this->admin)->get('/admin/guests/'.Guest::sole()->id.'/export')->assertOk();

    $data = json_decode($response->streamedContent(), true);

    expect($data['profile']['email'])->toBe('anna@example.com')
        ->and($data['stays'][0]['reference'])->toBe($booking->reference)
        // "Everything you hold about me" means everything — the note too.
        ->and($data['stays'][0]['guest_notes'])->toBe('Allergic to feathers');
});

it('erases the person and keeps the books', function (): void {
    $booking = guestStay('anna@example.com', daysAgo: 30, status: 'checked_out');
    $booking->forceFill(['guest_notes' => 'Allergic to feathers'])->save();

    $guest = Guest::sole();

    $this->actingAs($this->admin)
        ->post('/admin/guests/'.$guest->id.'/erase', ['confirm' => 'ERASE'])
        ->assertRedirect('/admin/guests');

    $guest->refresh();
    $booking->refresh();

    expect($guest->isAnonymised())->toBeTrue()
        ->and($guest->email)->toBe(GuestPrivacy::erasedEmail($guest))
        ->and($guest->phone)->toBeNull()
        ->and($guest->marketing_consent)->toBeFalse()
        // The guest's own words about themselves go with them.
        ->and($booking->guest_notes)->toBeNull()
        // The books stay: reports must not rewrite history when a
        // person leaves it.
        ->and($booking->total)->toBe(20000)
        ->and($booking->check_in->toDateString())->not->toBeNull()
        ->and($guest->total_spent)->toBe(20000);
});

it('refuses to erase a guest who is about to arrive', function (): void {
    guestStay('anna@example.com');   // confirmed, 14 days out
    $guest = Guest::sole();

    $this->actingAs($this->admin)
        ->post('/admin/guests/'.$guest->id.'/erase', ['confirm' => 'ERASE'])
        ->assertSessionHas('update_error');

    // Nobody can check in "Anonymised guest".
    expect($guest->fresh()->isAnonymised())->toBeFalse();
});

it('requires ERASE typed out in full', function (): void {
    guestStay('anna@example.com', daysAgo: 30, status: 'checked_out');

    $this->actingAs($this->admin)
        ->post('/admin/guests/'.Guest::sole()->id.'/erase', ['confirm' => 'erase'])
        ->assertSessionHasErrors('confirm');

    expect(Guest::sole()->isAnonymised())->toBeFalse();
});

it('turns the retention clock: long-gone guests are anonymised unasked', function (): void {
    config()->set('doba.privacy.retention_months', 24);

    $longGone = guestStay('old@example.com', daysAgo: 800, status: 'checked_out');
    $recent = guestStay('fresh@example.com', daysAgo: 30, status: 'checked_out');
    $returning = guestStay('back@example.com', daysAgo: 800, status: 'checked_out');
    guestStay('back@example.com');   // …but they have a stay coming up

    Artisan::call('doba:guests:anonymise');

    expect(Guest::query()->where('email', GuestPrivacy::erasedEmail(Guest::query()->whereNotNull('anonymised_at')->sole()))->exists())->toBeTrue()
        ->and(Guest::query()->whereNotNull('anonymised_at')->count())->toBe(1)
        ->and($recent->guest->fresh()->isAnonymised())->toBeFalse()
        // One future booking keeps the whole profile alive.
        ->and($returning->guest->fresh()->isAnonymised())->toBeFalse();

    // Rerunning finds nobody new — the clock does not tick twice.
    Artisan::call('doba:guests:anonymise');
    expect(Guest::query()->whereNotNull('anonymised_at')->count())->toBe(1);
});

it('exports only the consenting, and never the anonymised', function (): void {
    guestStay('yes@example.com', daysAgo: 30, status: 'checked_out');
    guestStay('no@example.com', daysAgo: 30, status: 'checked_out');
    guestStay('gone@example.com', daysAgo: 30, status: 'checked_out');

    Guest::query()->where('email', 'yes@example.com')->update(['marketing_consent' => true]);
    Guest::query()->where('email', 'gone@example.com')->update(['marketing_consent' => true]);

    app(GuestPrivacy::class)->erase(Guest::query()->where('email', 'gone@example.com')->sole());

    $csv = $this->actingAs($this->admin)->get('/admin/guests/export-consenting')->assertOk()->streamedContent();

    expect($csv)->toContain('yes@example.com')
        ->not->toContain('no@example.com')
        // Consent does not survive anonymisation — an erased guest on a
        // mailing list is the erasure not having happened.
        ->not->toContain('gone@example.com')
        ->not->toContain('anonymised.invalid');
});

it('greets a returning guest on the front desk arrivals list', function (): void {
    guestStay('anna@example.com', daysAgo: 100, status: 'checked_out');
    $upcoming = guestStay('anna@example.com');

    $upcoming->forceFill(['check_in' => CarbonImmutable::today(config('doba.timezone'))->toDateString()])->save();

    // The one fact that changes how the desk says hello.
    $this->actingAs($this->admin)->get('/admin/front-desk')
        ->assertOk()
        ->assertSee('2nd stay');
});
