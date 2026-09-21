<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Domain\Guests\GuestPrivacy;
use App\Enums\BookingStatus;
use App\Mail\PreArrival;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingRegistration;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Online check-in (§12): the registration form before arrival, encrypted
 * at rest, printed for the signature, and gone a year after the stay.
 */
function checkInStay(int $daysAhead = 2, int $adults = 2, bool $confirmed = true): Booking
{
    $roomType = RoomType::query()->where('code', 'OCI')->firstOr(function (): RoomType {
        $type = RoomType::create(['code' => 'OCI', 'base_occupancy' => 2, 'max_occupancy' => 3, 'max_adults' => 3, 'default_rate' => 10000, 'total_units' => 9]);
        $type->translations()->create(['locale' => 'en', 'slug' => 'oci', 'name' => 'Double']);

        foreach (range(0, 40) as $i) {
            Availability::create(['room_type_id' => $type->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString(), 'allotment' => 9]);
        }

        return $type;
    });

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays($daysAhead);

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => uniqid().'@example.com', 'first_name' => 'Anna', 'last_name' => 'Kowalska', 'country' => 'PL'], adults: $adults,
    );

    return $confirmed ? app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test')->fresh() : $booking->fresh();
}

function checkInUrl(Booking $booking): string
{
    return '/en/booking/manage/'.$booking->reference.'/'.$booking->manage_token;
}

function person(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Anna', 'last_name' => 'Kowalska', 'date_of_birth' => '1988-04-12', 'nationality' => 'PL',
        'street' => 'ul. Długa 5', 'postal_code' => '00-001', 'city' => 'Warszawa', 'country' => 'PL',
        'document_type' => 'passport', 'document_number' => 'EK1234567',
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    config()->set('doba.features.online_checkin', true);
    config()->set('doba.checkin.home_country', 'DE');
});

it('does not exist while the feature is off', function (): void {
    config()->set('doba.features.online_checkin', false);
    $booking = checkInStay();

    $this->get(checkInUrl($booking))->assertOk()->assertDontSee('Check in online');
    $this->get(checkInUrl($booking).'/check-in')->assertRedirect(checkInUrl($booking));
});

it('opens a few days before arrival, for confirmed bookings only', function (): void {
    $soon = checkInStay(daysAhead: 2);
    $far = checkInStay(daysAhead: 20);
    $hold = checkInStay(daysAhead: 2, confirmed: false);

    $this->get(checkInUrl($soon))->assertOk()->assertSee('Check in online');
    $this->get(checkInUrl($soon).'/check-in')->assertOk()->assertSee('Date of birth')->assertSee('Polska' === 'x' ? 'x' : 'Poland');

    $this->get(checkInUrl($far))->assertOk()->assertSee('Online check-in opens on')->assertDontSee('Check in online');
    $this->get(checkInUrl($far).'/check-in')->assertRedirect();
    $this->get(checkInUrl($hold).'/check-in')->assertRedirect();
});

it('takes the form for the whole party and stores it encrypted', function (): void {
    $booking = checkInStay(adults: 2);

    $this->post(checkInUrl($booking).'/check-in', [
        'party' => [person(), person(['first_name' => 'Piotr', 'date_of_birth' => '1986-01-30', 'document_number' => 'EK7654321', 'street' => null, 'postal_code' => null, 'city' => null, 'country' => null])],
        'arrival_time' => '16:30',
    ])->assertRedirect(checkInUrl($booking))->assertSessionHas('booking_notice');

    $registration = BookingRegistration::sole();

    expect($registration->party)->toHaveCount(2)
        ->and($registration->party[0]['document_number'])->toBe('EK1234567')
        ->and($registration->party[1]['first_name'])->toBe('Piotr')
        ->and(array_keys($registration->party[1]))->toBe(BookingRegistration::FIELDS)
        ->and($booking->fresh()->arrival_time)->toBe('16:30');

    // At rest, not one of those values is readable.
    $raw = (string) DB::table('booking_registrations')->value('party');

    expect($raw)->not->toContain('EK1234567')->not->toContain('Kowalska')->not->toContain('1988');

    $this->get(checkInUrl($booking))->assertSee('You are checked in online');

    // Changing the details replaces the form; it never becomes two.
    $this->post(checkInUrl($booking).'/check-in', ['party' => [person(['city' => 'Kraków']), person(['first_name' => 'Piotr'])]]);
    expect(BookingRegistration::query()->count())->toBe(1)
        ->and(BookingRegistration::sole()->party[0]['city'])->toBe('Kraków');
});

it('insists on a document for guests from abroad, and only for them', function (): void {
    $booking = checkInStay(adults: 1);

    $this->from(checkInUrl($booking).'/check-in')
        ->post(checkInUrl($booking).'/check-in', ['party' => [person(['document_type' => null, 'document_number' => null])]])
        ->assertSessionHasErrors('party.0.document_number');

    expect(BookingRegistration::query()->count())->toBe(0);

    // A guest from the hotel's own country is not asked.
    $this->post(checkInUrl($booking).'/check-in', ['party' => [person(['nationality' => 'DE', 'country' => 'DE', 'document_type' => null, 'document_number' => null])]])
        ->assertSessionHasNoErrors();

    // And a hotel whose law asks everybody says so in one setting.
    config()->set('doba.checkin.require_document', 'all');
    $strict = checkInStay(adults: 1);
    $this->from(checkInUrl($strict).'/check-in')
        ->post(checkInUrl($strict).'/check-in', ['party' => [person(['nationality' => 'DE', 'country' => 'DE', 'document_type' => null, 'document_number' => null])]])
        ->assertSessionHasErrors('party.0.document_number');
});

it('refuses a form for the wrong number of people, a made-up country, or a forged token', function (): void {
    $booking = checkInStay(adults: 2);

    $this->post(checkInUrl($booking).'/check-in', ['party' => [person()]])->assertSessionHasErrors('party');
    $this->post(checkInUrl($booking).'/check-in', ['party' => [person(['nationality' => 'XX']), person()]])->assertSessionHasErrors('party.0.nationality');
    $this->post('/en/booking/manage/'.$booking->reference.'/forged/check-in', ['party' => [person(), person()]])->assertNotFound();

    expect(BookingRegistration::query()->count())->toBe(0);
});

it('shows arrival instructions only to a checked-in guest, around arrival, and paid if the hotel wants it so', function (): void {
    Setting::put('checkin', 'instructions', ['en' => 'Key box by the side door, code 4711.'], true);
    HotelSettings::flush();
    app(HotelSettings::class)->refresh();

    $booking = checkInStay(daysAhead: 1, adults: 1);

    $this->get(checkInUrl($booking))->assertDontSee('4711');

    $this->post(checkInUrl($booking).'/check-in', ['party' => [person()]]);
    $this->get(checkInUrl($booking))->assertSee('code 4711');

    // Three days out the form is open, the code is not shown yet.
    $early = checkInStay(daysAhead: 3, adults: 1);
    $this->post(checkInUrl($early).'/check-in', ['party' => [person()]]);
    $this->get(checkInUrl($early))->assertSee('You are checked in online')->assertDontSee('4711');

    // "Only once it is paid."
    config()->set('doba.checkin.instructions_require_paid', true);
    $this->get(checkInUrl($booking))->assertDontSee('4711')->assertSee('as soon as the stay is paid');

    $booking->forceFill(['paid_amount' => $booking->total, 'balance_due' => 0])->save();
    $this->get(checkInUrl($booking))->assertSee('code 4711');
});

it('tells the desk, and prints the form for the signature — for staff only', function (): void {
    $booking = checkInStay(daysAhead: 0, adults: 1);
    $this->post(checkInUrl($booking).'/check-in', ['party' => [person()]]);
    $admin = User::factory()->create();

    $this->get('/admin/bookings/'.$booking->id.'/registration.pdf')->assertRedirect('/admin/login');

    $this->actingAs($admin)->get('/admin/front-desk')->assertOk()->assertSee('checked in online');

    $pdf = $this->actingAs($admin)->get('/admin/bookings/'.$booking->id.'/registration.pdf')
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($pdf->headers->get('Cache-Control'))->toContain('no-store')
        ->and($pdf->getContent())->toStartWith('%PDF');

    // No form, no document.
    $this->actingAs($admin)->get('/admin/bookings/'.checkInStay()->id.'/registration.pdf')->assertNotFound();
});

it('invites the guest in the pre-arrival mail, until they have done it', function (): void {
    $booking = checkInStay(adults: 1);

    expect((new PreArrival($booking))->render())->toContain('check in online');

    $this->post(checkInUrl($booking).'/check-in', ['party' => [person()]]);

    expect((new PreArrival($booking->fresh()))->render())->not->toContain('check in online');
});

it('hands the form over on a data request, destroys it on erasure, and after its retention period', function (): void {
    $booking = checkInStay(daysAhead: 0, adults: 1);
    $this->post(checkInUrl($booking).'/check-in', ['party' => [person()]]);

    $export = app(GuestPrivacy::class)->export($booking->guest);
    expect($export['stays'][0]['registration_form'][0]['document_number'])->toBe('EK1234567');

    // The retention clock: a year after departure, by default.
    $booking->forceFill(['check_in' => '2020-01-01', 'check_out' => '2020-01-03', 'status' => BookingStatus::CheckedOut])->save();
    $this->artisan('doba:guests:anonymise')->expectsOutputToContain('Destroyed 1 registration form');
    expect(BookingRegistration::query()->count())->toBe(0);

    // Erasure takes it at once.
    $second = checkInStay(daysAhead: 0, adults: 1);
    $this->post(checkInUrl($second).'/check-in', ['party' => [person()]]);
    app(BookingService::class)->transition($second, BookingStatus::Cancelled, 'test');
    app(GuestPrivacy::class)->erase($second->guest);

    expect(BookingRegistration::query()->count())->toBe(0);
});
