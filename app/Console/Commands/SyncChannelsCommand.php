<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Channels\ChannelSyncService;
use App\Models\ChannelFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pull every active iCal feed (§9). Scheduled every 15 minutes.
 *
 * One feed's failure never stops the rest — an OTA being down is the
 * ordinary case, not an exception — and the run reports staleness at the
 * end, because a sync that has quietly died looks exactly like a quiet
 * week until two guests arrive for one room.
 */
class SyncChannelsCommand extends Command
{
    protected $signature = 'channels:sync {--feed= : Sync only this feed id}';

    protected $description = 'Import OTA iCal calendars into availability';

    public function handle(ChannelSyncService $sync): int
    {
        $feeds = ChannelFeed::query()
            ->where('is_active', true)
            ->whereNotNull('import_url')
            ->when($this->option('feed'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($feeds->isEmpty()) {
            $this->info('No active feeds with an import URL.');

            return self::SUCCESS;
        }

        foreach ($feeds as $feed) {
            $result = $sync->sync($feed);

            if (isset($result['failed'])) {
                $this->warn(sprintf('%s: %s', $feed->name, (string) $result['error']));

                continue;
            }

            $this->line(sprintf(
                '%s: %d events, %d added, %d changed, %d released, %d flagged%s',
                $feed->name,
                (int) ($result['events'] ?? 0),
                (int) ($result['added'] ?? 0),
                (int) ($result['changed'] ?? 0),
                (int) ($result['released'] ?? 0),
                (int) ($result['flagged'] ?? 0),
                ($result['removals_considered'] ?? true) ? '' : ' (removals skipped: implausible event count)',
            ));
        }

        $unhealthy = ChannelFeed::query()->get()->filter->isUnhealthy();

        foreach ($unhealthy as $feed) {
            // Logged at error so it reaches whatever the install pages
            // with. A stale channel is a silent overbooking in waiting.
            Log::error('Channel feed is unhealthy.', [
                'feed' => $feed->id,
                'name' => $feed->name,
                'consecutive_errors' => $feed->consecutive_error_count,
                'last_success_at' => $feed->last_success_at?->toIso8601String(),
            ]);

            $this->error(sprintf('%s has not synced successfully in over an hour.', $feed->name));
        }

        return $unhealthy->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
