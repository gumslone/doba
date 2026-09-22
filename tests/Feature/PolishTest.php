<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Mail\PostStay;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Small things that make a week: the admin as a phone app, and the
 * thank-you mail that says why to come back direct.
 */
function polishStay(): Booking
{
    $type = RoomType::create(['code' => 'PL', 'default_rate' => 10000, 'total_units' => 1]);
    $type->translations()->create(['locale' => 'en', 'slug' => 'pl', 'name' => 'Double']);
    $guest = Guest::findOrCreateByEmail('anna@example.com', ['first_name' => 'Anna', 'last_name' => 'K']);
    $today = CarbonImmutable::today(config('doba.timezone'));

    return Booking::create([
        'reference' => Booking::nextReference(), 'manage_token' => Booking::newManageToken(),
        'status' => BookingStatus::CheckedOut, 'check_in' => $today->subDays(3), 'check_out' => $today->subDay(),
        'nights' => 2, 'adults' => 2, 'currency' => 'EUR', 'subtotal' => 20000, 'total' => 20000, 'locale' => 'en', 'guest_id' => $guest->id,
    ])->fresh();
}

it('is installable on a phone, with the manifest and icons behind the admin pages', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin/front-desk')
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/admin.webmanifest">', false)
        ->assertSee('apple-touch-icon', false);

    $manifest = json_decode((string) file_get_contents(public_path('admin.webmanifest')), true);

    expect($manifest['start_url'])->toBe('/admin/front-desk')
        ->and($manifest['display'])->toBe('standalone')
        ->and(file_exists(public_path('icons/doba-512.png')))->toBeTrue()
        ->and(getimagesize(public_path('icons/doba-512.png'))[0])->toBe(512);
});

it('tells a guest who now qualifies for the returning discount to book direct next time', function (): void {
    config()->set('doba.locales', ['en']);
    $booking = polishStay();

    // No scheme configured: nothing promised.
    expect((new PostStay($booking))->render())->not->toContain('% off');

    config()->set('doba.loyalty.discount_bps', 1000);
    config()->set('doba.loyalty.min_stays', 2);

    // One stay so far: not yet.
    $booking->guest->forceFill(['stays_count' => 1])->save();
    expect((new PostStay($booking->fresh()))->render())->not->toContain('% off');

    $booking->guest->forceFill(['stays_count' => 2])->save();
    $html = (new PostStay($booking->fresh()))->render();

    expect($html)->toContain('10% off')->toContain('/en/rooms');
});
