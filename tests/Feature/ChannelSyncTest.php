<?php

declare(strict_types=1);

use App\Domain\Channels\ChannelSyncService;
use App\Domain\Channels\Ical;
use App\Models\Availability;
use App\Models\ChannelBooking;
use App\Models\ChannelFeed;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Build a calendar payload from [uid => [start, end]] pairs.
 *
 * @param  array<string,array{0:string,1:string}>  $events
 */
function calendar(array $events): string
{
    $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n";

    foreach ($events as $uid => [$start, $end]) {
        $body .= "BEGIN:VEVENT\r\nUID:{$uid}\r\n"
            .'DTSTART;VALUE=DATE:'.str_replace('-', '', $start)."\r\n"
            .'DTEND;VALUE=DATE:'.str_replace('-', '', $end)."\r\n"
            ."SUMMARY:CLOSED - Not available\r\nEND:VEVENT\r\n";
    }

    return $body."END:VCALENDAR\r\n";
}

beforeEach(function (): void {
    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'default_rate' => 10000,
        'total_units' => 2,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    $this->start = CarbonImmutable::today()->addDays(30);

    foreach (range(0, 20) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $this->start->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    $this->feed = ChannelFeed::create([
        'room_type_id' => $this->roomType->id,
        'channel' => 'booking_com',
        'name' => 'Booking.com — Double',
        'import_url' => 'https://203.0.113.10/feed.ics',
    ]);

    $this->sync = app(ChannelSyncService::class);

    // One stub whose payload is swapped between syncs. Http::fake() MERGES
    // stubs rather than replacing them, so calling it again per sync would
    // leave the first response winning every time — and every "the feed
    // changed" test would silently assert nothing.
    $upstream = new stdClass;
    $upstream->body = calendar([]);
    $upstream->status = 200;

    Http::fake(['203.0.113.10/*' => fn () => Http::response($upstream->body, $upstream->status)]);

    $this->serve = function (array $events) use ($upstream): void {
        $upstream->body = calendar($events);
        $upstream->status = 200;
    };

    $this->respond = function (string $body, int $status) use ($upstream): void {
        $upstream->body = $body;
        $upstream->status = $status;
    };

    $this->stay = fn (int $offset, int $nights): array => [
        $this->start->addDays($offset)->toDateString(),
        $this->start->addDays($offset + $nights)->toDateString(),
    ];

    $this->booked = fn (int $offset): int => Availability::query()
        ->where('room_type_id', $this->roomType->id)
        ->where('date', $this->start->addDays($offset)->toDateString())
        ->value('booked');
});

it('reads DTEND as exclusive so the checkout night stays sellable', function (): void {
    // 15–18 September is three nights; the 18th is free.
    $events = Ical::parse(calendar(['a@ota' => ['2026-09-15', '2026-09-18']]));

    expect($events)->toHaveCount(1)
        ->and($events[0]->start->toDateString())->toBe('2026-09-15')
        ->and($events[0]->end->toDateString())->toBe('2026-09-18')
        ->and($events[0]->nights())->toBe(3);
});

it('refuses to parse a truncated calendar rather than reporting no events', function (): void {
    // The distinction the entire removal guard rests on: a feed with no
    // bookings and half a download must not look the same.
    expect(Ical::parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20260915"))->toBeNull()
        ->and(Ical::parse('<html>Service temporarily unavailable</html>'))->toBeNull()
        ->and(Ical::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR"))->toBe([]);
});

it('unfolds wrapped lines and strips property parameters', function (): void {
    $payload = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
        ."UID:very-long-reservation-identifier-from-an\r\n ota-that-wrapped@example.com\r\n"
        ."DTSTART;VALUE=DATE:20260915\r\nDTEND;VALUE=DATE:20260916\r\n"
        ."SUMMARY:Closed\\, unavailable\r\nEND:VEVENT\r\nEND:VCALENDAR";

    $events = Ical::parse($payload);

    expect($events[0]->uid)->toBe('very-long-reservation-identifier-from-anota-that-wrapped@example.com')
        ->and($events[0]->summary)->toBe('Closed, unavailable');
});

it('imports an OTA stay onto the nights it actually occupies', function (): void {
    ($this->serve)(['a@ota' => ($this->stay)(2, 3)]);

    $result = $this->sync->sync($this->feed);

    expect($result['added'])->toBe(1)
        ->and(($this->booked)(1))->toBe(0)   // the night before
        ->and(($this->booked)(2))->toBe(1)
        ->and(($this->booked)(4))->toBe(1)
        // Check-out day: the room is sellable again that night.
        ->and(($this->booked)(5))->toBe(0);
});

it('is idempotent, so re-importing the same feed changes nothing', function (): void {
    ($this->serve)(['a@ota' => ($this->stay)(2, 3)]);

    $this->sync->sync($this->feed);
    $second = $this->sync->sync($this->feed);

    expect($second['added'])->toBe(0)
        ->and(ChannelBooking::query()->count())->toBe(1)
        ->and(($this->booked)(2))->toBe(1);
});

it('moves a stay by releasing the old nights before taking the new ones', function (): void {
    ($this->serve)(['a@ota' => ($this->stay)(2, 3)]);
    $this->sync->sync($this->feed);

    ($this->serve)(['a@ota' => ($this->stay)(10, 2)]);
    $result = $this->sync->sync($this->feed);

    expect($result['changed'])->toBe(1)
        ->and(($this->booked)(2))->toBe(0)
        ->and(($this->booked)(10))->toBe(1)
        ->and(($this->booked)(11))->toBe(1)
        ->and(($this->booked)(12))->toBe(0);
});

it('does not release a stay until it has been missing from three consecutive syncs', function (): void {
    ($this->serve)(['a@ota' => ($this->stay)(20, 1), 'b@ota' => ($this->stay)(2, 3)]);
    $this->sync->sync($this->feed);

    // 'b' vanishes. Two syncs later the room is still blocked, because a
    // feed that hiccups twice has cost the hotel nothing yet.
    ($this->serve)(['a@ota' => ($this->stay)(20, 1)]);

    foreach ([1, 2] as $round) {
        $result = $this->sync->sync($this->feed);
        expect($result['released'])->toBe(0)
            ->and(($this->booked)(2))->toBe(1)
            ->and(ChannelBooking::query()->where('external_uid', 'b@ota')->value('missing_syncs'))->toBe($round);
    }

    expect($this->sync->sync($this->feed)['released'])->toBe(1)
        ->and(($this->booked)(2))->toBe(0);
});

it('forgets the missing count when a hiccuping feed brings the stay back', function (): void {
    ($this->serve)(['a@ota' => ($this->stay)(20, 1), 'b@ota' => ($this->stay)(2, 3)]);
    $this->sync->sync($this->feed);

    ($this->serve)(['a@ota' => ($this->stay)(20, 1)]);
    $this->sync->sync($this->feed);
    $this->sync->sync($this->feed);

    ($this->serve)(['a@ota' => ($this->stay)(20, 1), 'b@ota' => ($this->stay)(2, 3)]);
    $this->sync->sync($this->feed);

    // Back to zero, so the counter never accumulates across unrelated
    // outages until an unlucky third one releases a live booking.
    expect(ChannelBooking::query()->where('external_uid', 'b@ota')->value('missing_syncs'))->toBe(0);

    ($this->serve)(['a@ota' => ($this->stay)(20, 1)]);
    $this->sync->sync($this->feed);

    expect(($this->booked)(2))->toBe(1);
});

it('flags an imminent stay for staff instead of auto-releasing it', function (): void {
    $soon = CarbonImmutable::today()->addDays(3);

    foreach (range(0, 2) as $i) {
        Availability::create([
            'room_type_id' => $this->roomType->id,
            'date' => $soon->addDays($i)->toDateString(),
            'allotment' => 2,
        ]);
    }

    ($this->serve)([
        'far@ota' => ($this->stay)(2, 2),
        'soon@ota' => [$soon->toDateString(), $soon->addDays(2)->toDateString()],
    ]);
    $this->sync->sync($this->feed);

    ($this->serve)(['far@ota' => ($this->stay)(2, 2)]);

    $result = null;
    foreach (range(1, 3) as $ignored) {
        $result = $this->sync->sync($this->feed);
    }

    $block = ChannelBooking::query()->where('external_uid', 'soon@ota')->sole();

    expect($result['released'])->toBe(0)
        ->and($result['flagged'])->toBe(1)
        ->and($block->needs_review)->toBeTrue()
        ->and($block->released_at)->toBeNull()
        // Still blocked: releasing it late costs a night, releasing it
        // wrongly costs a guest standing at the desk.
        ->and(Availability::query()->where('date', $soon->toDateString())->value('booked'))->toBe(1);
});

it('skips removals entirely when a feed shrinks implausibly', function (): void {
    $events = [];
    foreach (range(0, 9) as $i) {
        $events["e{$i}@ota"] = ($this->stay)($i, 1);
    }

    ($this->serve)($events);
    $this->sync->sync($this->feed);

    expect(ChannelBooking::query()->count())->toBe(10);

    // A truncated response wearing an HTTP 200. Acting on it would start
    // nine stays down the road to release.
    ($this->serve)(['e0@ota' => ($this->stay)(0, 1)]);

    $result = $this->sync->sync($this->feed);

    expect($result['removals_considered'])->toBeFalse()
        ->and(ChannelBooking::query()->where('missing_syncs', '>', 0)->count())->toBe(0)
        ->and(($this->booked)(5))->toBe(1)
        ->and($this->feed->fresh()->last_error)->toContain('implausible');
});

it('still lets a small feed empty out, since two cancellations are ordinary', function (): void {
    ($this->serve)(['a@ota' => ($this->stay)(2, 1), 'b@ota' => ($this->stay)(4, 1)]);
    $this->sync->sync($this->feed);

    ($this->serve)([]);

    foreach (range(1, 3) as $ignored) {
        $result = $this->sync->sync($this->feed);
    }

    expect($result['released'])->toBe(2)
        ->and(($this->booked)(2))->toBe(0);
});

it('counts errors and never touches inventory when the fetch fails', function (): void {
    ($this->serve)(['a@ota' => ($this->stay)(2, 3)]);
    $this->sync->sync($this->feed);

    foreach ([['', 503], ['<html>Down</html>', 200]] as $i => [$body, $status]) {
        ($this->respond)($body, $status);

        $result = $this->sync->sync($this->feed);

        expect($result['failed'])->toBeTrue()
            ->and($this->feed->fresh()->consecutive_error_count)->toBe($i + 1)
            // The nights stay blocked through every kind of failure.
            ->and(($this->booked)(2))->toBe(1);
    }

    expect($this->feed->fresh()->isUnhealthy())->toBeFalse(); // two errors is not yet a crisis

    ($this->respond)('', 500);
    $this->sync->sync($this->feed);

    expect($this->feed->fresh()->isUnhealthy())->toBeTrue();
});

it('exports blocked nights as merged ranges with an exclusive end', function (): void {
    // Sell out nights 2, 3 and 4 and, separately, night 8.
    Availability::query()
        ->whereIn('date', [
            $this->start->addDays(2)->toDateString(),
            $this->start->addDays(3)->toDateString(),
            $this->start->addDays(4)->toDateString(),
        ])
        ->update(['booked' => 2]);

    Availability::query()
        ->where('date', $this->start->addDays(8)->toDateString())
        ->update(['closed' => true]);

    $ics = $this->sync->export($this->roomType, $this->start, 20);
    $events = Ical::parse($ics);

    expect($events)->toHaveCount(2)
        ->and($events[0]->start->toDateString())->toBe($this->start->addDays(2)->toDateString())
        // Exclusive: the run of blocked nights ends the morning after the
        // last one, which is what a subscriber must read back.
        ->and($events[0]->end->toDateString())->toBe($this->start->addDays(5)->toDateString())
        ->and($events[1]->start->toDateString())->toBe($this->start->addDays(8)->toDateString())
        ->and($events[1]->end->toDateString())->toBe($this->start->addDays(9)->toDateString());
});

it('serves the export only to the right token and never leaks guest data', function (): void {
    Availability::query()
        ->where('date', $this->start->addDays(2)->toDateString())
        ->update(['booked' => 2]);

    $token = $this->roomType->fresh()->ical_token;

    $this->get("/ical/{$this->roomType->id}/".str_repeat('x', 40).'.ics')->assertNotFound();

    $response = $this->get("/ical/{$this->roomType->id}/{$token}.ics");

    $response->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

    $body = $response->getContent();

    expect($body)->toContain('BEGIN:VCALENDAR')
        ->and($body)->toContain('Not available')
        // The URL is handed to third parties and cannot log anyone in, so
        // the feed says the room is gone and nothing about who has it.
        ->and($body)->not->toContain('@example.com')
        ->and($body)->not->toContain('EUR');
});

it('gives every room type its own token without ever accepting one from a request', function (): void {
    $other = RoomType::create([
        'code' => 'SGL', 'base_occupancy' => 1, 'max_occupancy' => 1,
        'default_rate' => 8000, 'total_units' => 1,
        'ical_token' => 'attacker-supplied-token-that-must-be-ignored',
    ]);

    expect($other->ical_token)->not->toBe('attacker-supplied-token-that-must-be-ignored')
        ->and(strlen((string) $other->ical_token))->toBe(40)
        ->and($other->ical_token)->not->toBe($this->roomType->fresh()->ical_token);
});

it('refuses to fetch a feed URL that points inside the network', function (): void {
    // The URL arrives from a form, and an admin session is the first thing
    // an attacker gets. A fetcher that will follow these on request is an
    // SSRF hole no matter who typed them in.
    foreach ([
        'http://169.254.169.254/latest/meta-data/',
        'http://127.0.0.1:6379/',
        'http://10.0.0.5/feed.ics',
        'file:///etc/passwd',
        'gopher://203.0.113.10/',
    ] as $url) {
        $this->feed->forceFill(['import_url' => $url, 'consecutive_error_count' => 0])->save();

        $result = $this->sync->sync($this->feed);

        expect($result['failed'])->toBeTrue()
            ->and((string) $result['error'])->toContain('public http(s)');
    }

    Http::assertNothingSent();
});

it('runs the admin channel screens and releases a feed when it is deleted', function (): void {
    $admin = User::factory()->create();

    ($this->serve)(['a@ota' => ($this->stay)(2, 3)]);
    $this->sync->sync($this->feed);

    expect(($this->booked)(2))->toBe(1);

    $this->actingAs($admin)->get('/admin/channels')
        ->assertOk()
        ->assertSee($this->feed->name)
        // The limitation is stated on the page, not buried in a README.
        ->assertSee('cannot push prices', false)
        ->assertSee($this->roomType->fresh()->ical_token, false);

    $this->actingAs($admin)->delete("/admin/channels/{$this->feed->id}")->assertRedirect('/admin/channels');

    // Deleting the feed hands the nights back rather than leaving them
    // blocked with nothing left to explain why.
    expect(($this->booked)(2))->toBe(0)
        ->and(ChannelFeed::query()->count())->toBe(0);
});

it('lets staff resolve a flagged stay in either direction', function (): void {
    $admin = User::factory()->create();

    $block = ChannelBooking::create([
        'channel_feed_id' => $this->feed->id,
        'room_type_id' => $this->roomType->id,
        'external_uid' => 'flagged@ota',
        'check_in' => $this->start->toDateString(),
        'check_out' => $this->start->addDays(2)->toDateString(),
        'needs_review' => true,
        'missing_syncs' => 3,
    ]);

    Availability::query()
        ->whereIn('date', [$this->start->toDateString(), $this->start->addDay()->toDateString()])
        ->update(['booked' => 1]);

    $this->actingAs($admin)->post("/admin/channels/review/{$block->id}", ['decision' => 'keep'])
        ->assertRedirect('/admin/channels');

    expect($block->fresh())
        ->needs_review->toBeFalse()
        // Reset, so a feed that keeps omitting it does not re-flag it hourly.
        ->missing_syncs->toBe(0)
        ->released_at->toBeNull()
        ->and(($this->booked)(0))->toBe(1);

    $this->actingAs($admin)->post("/admin/channels/review/{$block->id}", ['decision' => 'release'])
        ->assertRedirect('/admin/channels');

    expect($block->fresh()->released_at)->not->toBeNull()
        ->and(($this->booked)(0))->toBe(0);
});

it('rejects a feed URL the sync would refuse to fetch anyway', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/admin/channels', [
            'name' => 'Malicious',
            'channel' => 'other',
            'room_type_id' => $this->roomType->id,
            'import_url' => 'file:///etc/passwd',
        ])
        ->assertSessionHasErrors('import_url');
});
