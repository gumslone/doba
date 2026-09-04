<?php

declare(strict_types=1);

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Mail\PostStay;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Review;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Verified guest reviews (§5, FEATURE_REVIEWS).
 *
 * The one property everything else hangs on: a review can only come
 * from a stay that actually happened. No account system, no anonymous
 * form, no imported stars — the manage token is the proof.
 */
function departedStay(string $email = 'anna@example.com'): Booking
{
    $roomType = RoomType::query()->firstOr(fn () => RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 5,
    ]));

    $checkIn = CarbonImmutable::today(config('doba.timezone'))->addDays(10);

    foreach (range(0, 12) as $i) {
        Availability::firstOrCreate(
            ['room_type_id' => $roomType->id, 'date' => CarbonImmutable::today(config('doba.timezone'))->addDays($i)->toDateString()],
            ['allotment' => 5],
        );
    }

    $booking = app(BookingService::class)->place(
        $roomType, $checkIn, $checkIn->addDays(2),
        ['email' => $email, 'first_name' => 'Anna', 'last_name' => 'K'],
        adults: 2,
    );

    app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'test');

    $booking->forceFill([
        'status' => BookingStatus::CheckedOut,
        'check_in' => CarbonImmutable::today(config('doba.timezone'))->subDays(3)->toDateString(),
        'check_out' => CarbonImmutable::today(config('doba.timezone'))->subDays(1)->toDateString(),
    ])->save();

    return $booking->fresh();
}

function reviewUrl(Booking $booking): string
{
    return "/en/booking/manage/{$booking->reference}/{$booking->manage_token}/review";
}

beforeEach(function (): void {
    config()->set('doba.features.reviews', true);
    config()->set('doba.locales', ['en']);
});

it('takes a review only from a departed stay, and only once', function (): void {
    $booking = departedStay();

    $this->post(reviewUrl($booking), [
        'rating' => 5,
        'title' => 'Quiet and warm',
        'body' => 'Wonderful stay, the breakfast alone is worth the trip.',
    ])->assertRedirect()->assertSessionHas('booking_notice');

    $review = Review::sole();

    // Unpublished until a human looks at it, in the language they booked in.
    expect($review->is_published)->toBeFalse()
        ->and($review->rating)->toBe(5)
        ->and($review->locale)->toBe('en')
        ->and($review->guest_id)->toBe($booking->guest_id);

    // The second attempt bounces: one stay, one verdict.
    $this->post(reviewUrl($booking), ['rating' => 1, 'body' => str_repeat('changed my mind entirely ', 3)])
        ->assertSessionHas('booking_error');

    expect(Review::query()->count())->toBe(1);
});

it('refuses a review before the stay has happened', function (): void {
    $booking = departedStay();
    $booking->forceFill([
        'status' => BookingStatus::Confirmed,
        'check_in' => CarbonImmutable::today(config('doba.timezone'))->addDays(5)->toDateString(),
        'check_out' => CarbonImmutable::today(config('doba.timezone'))->addDays(7)->toDateString(),
    ])->save();

    // A stay that has not happened yet has nothing to review.
    $this->post(reviewUrl($booking), ['rating' => 5, 'body' => str_repeat('looking forward to it! ', 3)])
        ->assertSessionHas('booking_error');

    expect(Review::query()->count())->toBe(0);
});

it('is invisible everywhere while the feature is off', function (): void {
    config()->set('doba.features.reviews', false);

    $booking = departedStay();

    // The form does not render, the route 404s, the mail does not ask.
    $this->get("/en/booking/manage/{$booking->reference}/{$booking->manage_token}")
        ->assertOk()->assertDontSee('review');
    $this->post(reviewUrl($booking), ['rating' => 5, 'body' => str_repeat('great stay all round ', 3)])
        ->assertNotFound();

    expect((new PostStay($booking))->render())->not->toContain('review');
});

it('shows only published reviews, and carries the stars into the schema', function (): void {
    $first = departedStay();
    $second = departedStay('b@example.com');

    $this->post(reviewUrl($first), ['rating' => 5, 'body' => 'Wonderful stay, the breakfast alone is worth the trip.']);
    $this->post(reviewUrl($second), ['rating' => 4, 'title' => 'Very good', 'body' => 'Lovely house, slightly slow WiFi in the annex rooms.']);

    // Nothing shows before moderation: no reviews on the page, no
    // aggregate in the schema — stars in a search result must be earned.
    $this->get('/en')->assertOk()->assertDontSee('aggregateRating');

    Review::query()->update(['is_published' => true, 'published_at' => now()]);

    $home = $this->get('/en')->assertOk();

    $home->assertSee('What our guests say')
        ->assertSee('verified stay')
        ->assertSee('slightly slow WiFi', false)
        ->assertSee('aggregateRating')
        ->assertSee('"ratingValue":4.5', false)
        ->assertSee('"reviewCount":2', false);
});

it('lets the hotel reply in public but never edit the words', function (): void {
    $booking = departedStay();
    $this->post(reviewUrl($booking), ['rating' => 2, 'body' => 'The road outside was noisy at six in the morning.']);

    $admin = User::factory()->create();
    $review = Review::sole();

    $this->actingAs($admin)->post("/admin/reviews/{$review->id}/publish");
    $this->actingAs($admin)->post("/admin/reviews/{$review->id}/respond", [
        'hotel_response' => 'Thank you — we have since fitted soundproof windows on the road side.',
    ]);

    $review->refresh();

    expect($review->is_published)->toBeTrue()
        ->and($review->hotel_response)->toContain('soundproof')
        // The guest's words are exactly as written: there is no route,
        // no form and no code path that changes them.
        ->and($review->body)->toBe('The road outside was noisy at six in the morning.');

    // The reply shows under the review, in public.
    $this->get('/en')->assertSee('noisy at six')->assertSee('soundproof windows');

    // Unpublish takes it — and the aggregate — off the site.
    $this->actingAs($admin)->post("/admin/reviews/{$review->id}/unpublish");
    $this->get('/en')->assertDontSee('noisy at six')->assertDontSee('aggregateRating');
});

it('keeps moderation behind the admin session', function (): void {
    $booking = departedStay();
    $this->post(reviewUrl($booking), ['rating' => 5, 'body' => 'Wonderful stay, the breakfast alone is worth it.']);

    $review = Review::sole();

    $this->get('/admin/reviews')->assertRedirect('/admin/login');
    $this->post("/admin/reviews/{$review->id}/publish")->assertRedirect('/admin/login');

    expect($review->fresh()->is_published)->toBeFalse();
});

it('asks for the review in the post-stay mail, once, in the guest language', function (): void {
    $booking = departedStay();
    $booking->forceFill(['locale' => 'de'])->save();

    expect((new PostStay($booking->fresh()))->render())->toContain('Nur echte Gäste');

    // Already reviewed: the ask disappears rather than nagging.
    Review::create(['booking_id' => $booking->id, 'guest_id' => $booking->guest_id, 'rating' => 5, 'body' => str_repeat('x', 20), 'locale' => 'de']);

    expect((new PostStay($booking->fresh()))->render())->not->toContain('Nur echte Gäste');
});
