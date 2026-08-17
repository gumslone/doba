<?php

declare(strict_types=1);

namespace App\Domain\Channels;

use App\Models\Availability;
use App\Models\ChannelBooking;
use App\Models\ChannelFeed;
use App\Models\RoomType;
use App\Support\Hotel\HotelSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tier-1 iCal sync (§9). Fetch each feed, add what is new, and — very
 * carefully — release what is genuinely gone.
 *
 * Additions are cheap to get wrong in one direction and catastrophic in
 * the other, so the two directions are not treated alike:
 *
 *  - **Adding** a block that turns out to be spurious costs the hotel one
 *    unsold night, visible in the grid and undoable by hand.
 *  - **Releasing** a block that was never actually cancelled sells a room
 *    that an OTA has already promised to somebody, and the hotel finds out
 *    when that somebody arrives.
 *
 * So a removal has to clear three separate hurdles: the feed must have
 * parsed completely, its event count must be plausible against the last
 * sync, and the event must have been absent from three consecutive good
 * syncs. Stays starting within a week are never released automatically at
 * all — they are flagged for a human.
 */
class ChannelSyncService
{
    /** An event must be missing from this many consecutive good syncs. */
    public const MISSING_SYNCS_BEFORE_RELEASE = 3;

    /** Stays starting inside this window are flagged, never auto-released. */
    public const REVIEW_WINDOW_DAYS = 7;

    /**
     * Sync one feed. Never throws: a dead OTA must not stop the others.
     *
     * @return array<string,int|string|bool>
     */
    public function sync(ChannelFeed $feed, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        if ($feed->import_url === null || ! $feed->is_active) {
            return ['skipped' => true];
        }

        if (! $this->isFetchable($feed->import_url)) {
            return $this->fail($feed, 'Import URL must be a public http(s) address.', $now);
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['Accept' => 'text/calendar, text/plain'])
                ->get($feed->import_url);

            if (! $response->successful()) {
                return $this->fail($feed, "HTTP {$response->status()}", $now);
            }

            $events = Ical::parse($response->body());

            if ($events === null) {
                // A 200 carrying an error page or half a calendar. Treated
                // as a failure precisely so it cannot reach the removal
                // path with an empty event list.
                return $this->fail($feed, 'Response is not a complete iCalendar document.', $now);
            }
        } catch (Throwable $e) {
            return $this->fail($feed, $e->getMessage(), $now);
        }

        $plausible = $this->countIsPlausible($feed, count($events));

        $result = DB::transaction(fn (): array => $this->apply($feed, $events, $plausible, $now));

        $feed->forceFill([
            'last_synced_at' => $now,
            'last_success_at' => $now,
            'last_event_count' => count($events),
            'consecutive_error_count' => 0,
            'last_error' => $plausible ? null : $this->implausibleMessage($feed, count($events)),
        ])->save();

        if (! $plausible) {
            Log::warning('Channel feed shrank implausibly; removals were skipped.', [
                'feed' => $feed->id,
                'was' => $feed->getOriginal('last_event_count'),
                'now' => count($events),
            ]);
        }

        return $result + ['events' => count($events), 'removals_considered' => $plausible];
    }

    /**
     * Hand a single block's nights back, on staff instruction or because
     * its feed is being removed.
     */
    public function releaseBlock(ChannelBooking $block, ?CarbonImmutable $now = null): void
    {
        if ($block->released_at !== null) {
            return; // already given back; releasing twice would undercount
        }

        DB::transaction(function () use ($block, $now): void {
            $this->releaseNights($block);

            $block->forceFill([
                'released_at' => $now ?? CarbonImmutable::now(),
                'needs_review' => false,
            ])->save();
        });
    }

    /**
     * Release everything a feed is still holding — deleting the feed
     * otherwise leaves its nights blocked with nothing left to explain why.
     */
    public function releaseFeed(ChannelFeed $feed): void
    {
        foreach ($feed->bookings()->holding()->get() as $block) {
            $this->releaseBlock($block);
        }
    }

    /**
     * @param  array<int,IcalEvent>  $events
     * @return array<string,int>
     */
    protected function apply(ChannelFeed $feed, array $events, bool $plausible, CarbonImmutable $now): array
    {
        $existing = $feed->bookings()->holding()->get()->keyBy('external_uid');
        $seen = [];
        $added = 0;
        $changed = 0;

        foreach ($events as $event) {
            $seen[$event->uid] = true;
            $block = $existing->get($event->uid);

            if ($block === null) {
                $this->createBlock($feed, $event);
                $added++;

                continue;
            }

            // An OTA may move a stay. Release the old nights and take the
            // new ones rather than editing dates under a live counter.
            if (! $block->check_in->isSameDay($event->start) || ! $block->check_out->isSameDay($event->end)) {
                $this->releaseNights($block);
                $block->forceFill([
                    'check_in' => $event->start,
                    'check_out' => $event->end,
                    'summary' => $event->summary,
                ])->save();
                $this->holdNights($block);
                $changed++;
            }

            if ($block->missing_syncs > 0) {
                // It came back — a feed hiccup, not a cancellation.
                $block->forceFill(['missing_syncs' => 0, 'missing_since' => null])->save();
            }
        }

        if (! $plausible) {
            return ['added' => $added, 'changed' => $changed, 'released' => 0, 'flagged' => 0];
        }

        return ['added' => $added, 'changed' => $changed]
            + $this->considerRemovals($existing, $seen, $now);
    }

    /**
     * @param  Collection<string,ChannelBooking>  $existing
     * @param  array<string,bool>  $seen
     * @return array<string,int>
     */
    protected function considerRemovals(Collection $existing, array $seen, CarbonImmutable $now): array
    {
        $released = 0;
        $flagged = 0;

        foreach ($existing as $uid => $block) {
            if (isset($seen[$uid])) {
                continue;
            }

            $block->forceFill([
                'missing_syncs' => $block->missing_syncs + 1,
                'missing_since' => $block->missing_since ?? $now,
            ])->save();

            if ($block->missing_syncs < self::MISSING_SYNCS_BEFORE_RELEASE) {
                continue;
            }

            if ($block->check_in->lt($now->addDays(self::REVIEW_WINDOW_DAYS)->startOfDay())) {
                // Too close to arrival to gamble on the feed being right.
                // Staff decide, and until they do the room stays blocked.
                if (! $block->needs_review) {
                    $block->forceFill(['needs_review' => true])->save();
                    $flagged++;
                }

                continue;
            }

            $this->releaseNights($block);
            $block->forceFill(['released_at' => $now])->save();
            $released++;
        }

        return ['released' => $released, 'flagged' => $flagged];
    }

    protected function createBlock(ChannelFeed $feed, IcalEvent $event): ChannelBooking
    {
        $block = $feed->bookings()->create([
            'room_type_id' => $feed->room_type_id,
            'external_uid' => $event->uid,
            // Never the guest's name if the OTA sent one: this row is
            // availability, and a name here would leak into the export.
            'summary' => $event->summary === null ? null : mb_substr($event->summary, 0, 255),
            'check_in' => $event->start,
            'check_out' => $event->end,
            'units' => 1,
        ]);

        $this->holdNights($block);

        return $block;
    }

    /**
     * Take the nights on the availability rows.
     *
     * Increments `booked`, the same counter a direct booking uses, under
     * the same lock and the same CHECK constraint — an OTA guest occupies
     * the room exactly as a direct guest does. If the constraint refuses
     * the write the room really was already sold twice, and that is worth
     * an alert rather than a silent swallow.
     */
    protected function holdNights(ChannelBooking $block): void
    {
        $rows = $this->lockNights($block);

        foreach ($rows as $row) {
            if ($row->unitsLeft() < $block->units) {
                // The overbooking already happened out on the channels;
                // the hotelier has to know tonight, not at check-in.
                Log::error('Channel block cannot fit in remaining inventory.', [
                    'channel_booking' => $block->id,
                    'date' => $row->date->toDateString(),
                ]);

                $block->forceFill(['needs_review' => true])->save();

                return;
            }
        }

        Availability::query()->whereIn('id', $rows->pluck('id'))->increment('booked', $block->units);
    }

    protected function releaseNights(ChannelBooking $block): void
    {
        $rows = $this->lockNights($block);

        Availability::query()
            ->whereIn('id', $rows->pluck('id'))
            // Floor at zero: a reconcile or a manual edit may already have
            // taken the counter down, and a negative would trip the CHECK
            // constraint and abort a sync over bookkeeping.
            ->where('booked', '>=', $block->units)
            ->decrement('booked', $block->units);
    }

    /**
     * The nights a stay actually consumes: check-in through the night
     * before check-out (§6). The checkout date is free.
     *
     * @return Collection<int,Availability>
     */
    protected function lockNights(ChannelBooking $block): Collection
    {
        return Availability::query()
            ->where('room_type_id', $block->room_type_id)
            ->where('date', '>=', $block->check_in->toDateString())
            ->where('date', '<', $block->check_out->toDateString())
            ->lockForUpdate()
            ->get();
    }

    /**
     * Refuse to fetch anything that is not a public http(s) address.
     *
     * The URL comes from a form, and a URL fetcher that will follow
     * `http://169.254.169.254/` or `http://localhost:6379/` on request is
     * an SSRF hole regardless of who filled the form in — an admin session
     * is exactly what an attacker gets first.
     */
    protected function isFetchable(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return false;
        }

        // A hostname is resolved first, so `internal.example.com` pointing
        // at 10.0.0.5 is caught alongside a literal address.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (array) gethostbynamel($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is this event count believable, given the last one?
     *
     * A feed that goes from 40 events to 38 is two cancellations. One that
     * goes from 40 to 3 is a truncated response wearing a 200, and acting
     * on it would release 37 stays in one pass.
     */
    protected function countIsPlausible(ChannelFeed $feed, int $count): bool
    {
        $previous = $feed->last_event_count;

        if ($previous === null || $previous === 0) {
            return true; // nothing to compare against; there is also nothing to release
        }

        // Small feeds are allowed to empty out — losing both of two
        // bookings is an ordinary Tuesday, losing 37 of 40 is not.
        if ($previous - $count <= 2) {
            return true;
        }

        return $count >= (int) ceil($previous / 2);
    }

    protected function implausibleMessage(ChannelFeed $feed, int $count): string
    {
        return sprintf(
            'Feed returned %d events after %d last sync; removals were skipped as implausible.',
            $count,
            (int) $feed->getOriginal('last_event_count'),
        );
    }

    /**
     * @return array<string,int|string|bool>
     */
    protected function fail(ChannelFeed $feed, string $error, CarbonImmutable $now): array
    {
        $feed->forceFill([
            'last_synced_at' => $now,
            'consecutive_error_count' => $feed->consecutive_error_count + 1,
            'last_error' => mb_substr($error, 0, 1000),
        ])->save();

        Log::warning('Channel sync failed.', [
            'feed' => $feed->id,
            'consecutive_errors' => $feed->consecutive_error_count,
            'error' => $error,
        ]);

        return ['failed' => true, 'error' => $error];
    }

    /**
     * The export feed for a room type: every night it is unavailable,
     * collapsed into as few VEVENTs as the calendar allows.
     *
     * Built from `availability` rather than from the bookings table, so a
     * night closed by the hotelier, sold direct or held by another channel
     * all export identically — the OTA needs "not for sale", not why.
     */
    public function export(RoomType $roomType, ?CarbonImmutable $from = null, ?int $days = null): string
    {
        $from ??= CarbonImmutable::today();
        $days ??= (int) config('doba.booking.booking_window_days', 540);

        $rows = Availability::query()
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [$from->toDateString(), $from->addDays($days)->toDateString()])
            ->orderBy('date')
            ->get();

        $events = [];
        $start = null;
        $previous = null;

        foreach ($rows as $row) {
            if (! ($row->closed || $row->unitsLeft() < 1)) {
                continue;
            }

            // A gap in the dates ends the run as surely as a sellable
            // night does: an unwritten night is not a blocked one, and
            // merging across it would export a block nobody made.
            $continues = $previous !== null && $row->date->isSameDay($previous->addDay());

            if ($start !== null && ! $continues) {
                $events[] = $this->blockEvent($roomType, $start, $previous->addDay());
                $start = null;
            }

            $start ??= $row->date;
            $previous = $row->date;
        }

        if ($start !== null && $previous !== null) {
            // DTEND is exclusive, so the run ends the morning after its
            // last blocked night.
            $events[] = $this->blockEvent($roomType, $start, $previous->addDay());
        }

        return Ical::write(
            // The hotel's own name, not APP_NAME: this string is what a
            // hotelier sees in their Booking.com calendar list.
            sprintf('%s — %s', app(HotelSettings::class)->name, $roomType->t('name') ?? $roomType->code),
            $events,
        );
    }

    protected function blockEvent(RoomType $roomType, CarbonImmutable $start, CarbonImmutable $end): IcalEvent
    {
        return new IcalEvent(
            // Stable across exports so subscribers update rather than
            // duplicate: same room, same range, same UID.
            uid: sprintf('doba-%d-%s-%s@%s', $roomType->id, $start->format('Ymd'), $end->format('Ymd'), parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'doba'),
            start: $start,
            end: $end,
            summary: 'Not available',
        );
    }
}
