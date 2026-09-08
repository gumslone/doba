<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Mail\BookingConfirmed;
use App\Mail\PostStay;
use App\Mail\PreArrival;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\Wording;
use Carbon\CarbonImmutable;

/**
 * Hotelier-editable mail wording (§13): the shipped text is the fallback
 * in every language, the hotel's own words win where it wrote them, and
 * the placeholders keep working either way.
 */
function wordedStay(string $locale = 'en'): Booking
{
    $roomType = RoomType::create(['code' => 'DBL-'.uniqid(), 'default_rate' => 10000, 'total_units' => 1]);
    $roomType->translations()->create(['locale' => 'en', 'slug' => 'dbl-'.$roomType->id, 'name' => 'Double']);

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(3);

    foreach (range(0, 2) as $i) {
        Availability::create(['room_type_id' => $roomType->id, 'date' => $checkIn->addDays($i)->toDateString(), 'allotment' => 1]);
    }

    $guest = Guest::findOrCreateByEmail('anna@example.com', ['first_name' => 'Anna', 'last_name' => 'Kowalska']);

    $booking = Booking::create([
        'reference' => Booking::nextReference(),
        'manage_token' => Booking::newManageToken(),
        'status' => BookingStatus::Confirmed,
        'check_in' => $checkIn,
        'check_out' => $checkIn->addDays(2),
        'nights' => 2,
        'adults' => 2,
        'currency' => 'EUR',
        'subtotal' => 20000,
        'total' => 20000,
        'balance_due' => 0,
        'locale' => $locale,
        'guest_id' => $guest->id,
    ]);

    $booking->rooms()->create(['room_type_id' => $roomType->id, 'adults' => 2, 'children' => 0, 'price_total' => 20000, 'refundable_snapshot' => true]);

    return $booking->fresh();
}

function wordingStore(string $key, array $byLocale): void
{
    Setting::put(Wording::GROUP, $key, $byLocale, true);
    HotelSettings::flush();
    app(HotelSettings::class)->refresh();
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    Setting::put('general', 'name', 'Hotel Alpenhof');
    HotelSettings::flush();
    app(HotelSettings::class)->refresh();
});

it('sends the shipped wording when the hotel wrote nothing', function (): void {
    $booking = wordedStay('en');
    $mail = new BookingConfirmed($booking);

    expect($mail->envelope()->subject)->toBe("Your booking {$booking->reference} is confirmed")
        ->and($mail->render())->toContain('Thank you, Anna — we are looking forward to your visit.');
});

it('sends the hotel\'s own words, placeholders filled, in the language it wrote them', function (): void {
    wordingStore('booking_intro', ['en' => 'Servus :name, your room at :hotel is yours — reference :reference.']);
    wordingStore('booking_subject', ['en' => ':hotel: booking :reference']);

    $booking = wordedStay('en');
    $mail = new BookingConfirmed($booking);

    expect($mail->envelope()->subject)->toBe("Hotel Alpenhof: booking {$booking->reference}")
        ->and($mail->render())->toContain("Servus Anna, your room at Hotel Alpenhof is yours — reference {$booking->reference}.");

    // German was never written: the shipped German text still goes out,
    // not an empty line and not the English override.
    $german = new BookingConfirmed(wordedStay('de'));

    expect($german->render())->not->toContain('Servus')
        ->and($german->render())->toContain('Anna');
});

it('covers every editable text in the three mails', function (): void {
    foreach (array_keys(Wording::KEYS) as $key) {
        wordingStore($key, ['en' => "[[{$key}]]"]);
    }

    $booking = wordedStay('en');
    $rendered = (new BookingConfirmed($booking))->render()
        .(new PreArrival($booking))->render()
        .(new PostStay($booking))->render()
        .(new BookingConfirmed($booking))->envelope()->subject
        .(new PreArrival($booking))->envelope()->subject
        .(new PostStay($booking))->envelope()->subject;

    foreach (array_keys(Wording::KEYS) as $key) {
        expect($rendered)->toContain("[[{$key}]]");
    }
});

it('never renders markup a hotelier pasted into the wording', function (): void {
    wordingStore('post_stay_intro', ['en' => 'Thanks <b>:name</b> <script>alert(1)</script>']);

    $rendered = (new PostStay(wordedStay('en')))->render();

    expect($rendered)->not->toContain('<script>')
        ->and($rendered)->not->toContain('<b>Anna</b>')
        ->and($rendered)->toContain('Thanks &lt;b&gt;Anna&lt;/b&gt;');
});

it('is edited in the panel, per language, and emptied back to the shipped text', function (): void {
    $admin = User::factory()->create();

    $this->get('/admin/mail/wording')->assertRedirect('/admin/login');

    $this->actingAs($admin)->get('/admin/mail/wording')
        ->assertOk()
        // The shipped text is the placeholder, in each language.
        ->assertSee('placeholder="Thank you, :name — we are looking forward to your visit."', false)
        ->assertSee('placeholder="Vielen Dank, :name', false);

    $this->actingAs($admin)->put('/admin/mail/wording', [
        'texts' => [
            'booking_intro' => ['en' => 'Welcome, :name!', 'de' => ''],
            // Identical to the shipped text: not worth storing, or a later
            // improvement to the shipped wording would never arrive.
            'post_stay_outro' => ['en' => Wording::shipped('post_stay_outro', 'en')],
        ],
    ])->assertRedirect('/admin/mail/wording')->assertSessionHas('saved');

    expect(Wording::custom('booking_intro', 'en'))->toBe('Welcome, :name!')
        ->and(Wording::custom('booking_intro', 'de'))->toBeNull()
        ->and(Wording::custom('post_stay_outro', 'en'))->toBeNull()
        ->and(Setting::query()->where('group', Wording::GROUP)->where('key', 'post_stay_outro')->value('value'))->toBeNull();

    // Emptying the box restores the shipped text; the German entry a
    // colleague wrote meanwhile is not touched by a form that showed it.
    wordingStore('booking_intro', ['en' => 'Welcome, :name!', 'de' => 'Grüß Gott, :name!']);

    $this->actingAs($admin)->put('/admin/mail/wording', [
        'texts' => ['booking_intro' => ['en' => '', 'de' => 'Grüß Gott, :name!']],
    ])->assertRedirect();

    expect(Wording::custom('booking_intro', 'en'))->toBeNull()
        ->and(Wording::custom('booking_intro', 'de'))->toBe('Grüß Gott, :name!')
        ->and((new BookingConfirmed(wordedStay('de')))->render())->toContain('Grüß Gott, Anna!');
});
