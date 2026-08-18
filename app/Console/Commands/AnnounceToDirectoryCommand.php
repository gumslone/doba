<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Directory\PropertyDescriptor;
use App\Support\Hotel\HotelSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tell the hub this hotel exists (§21).
 *
 * A ping, and deliberately nothing more: the URL and the install id, and
 * an invitation to come and read `/.well-known/doba.json`.
 *
 * There is no shared secret and no signature, because there is nothing
 * here worth signing. Every fact about the hotel comes from the hub
 * fetching the descriptor over HTTPS from the domain that claims it —
 * which is the proof, and a better one than a key a hotelier would have
 * to be issued and would eventually paste into the wrong field. Anyone
 * can announce any URL; all they achieve is asking the hub to read a
 * document that is already public.
 *
 * Run nightly, because a hotel that changes its photos and never says so
 * sits in a directory looking like it did last year.
 */
class AnnounceToDirectoryCommand extends Command
{
    protected $signature = 'doba:directory:announce {--force : Announce even when listing is switched off}';

    protected $description = 'Tell the directory hub that this hotel is here and its details have changed';

    public function handle(HotelSettings $hotel): int
    {
        if (! PropertyDescriptor::isEnabled() && ! $this->option('force')) {
            $this->line('Directory listing is off for this hotel. Nothing was sent.');

            return self::SUCCESS;
        }

        $hub = rtrim(PropertyDescriptor::hub(), '/');
        $url = rtrim((string) config('app.url'), '/');

        if (! str_starts_with($url, 'https://')) {
            // The hub will refuse it anyway, and saying so here is the
            // difference between a hotelier fixing APP_URL and a hotelier
            // wondering for a week why they never appeared.
            $this->error('APP_URL is '.$url.'. A hub cannot verify a listing it cannot fetch over HTTPS.');

            return self::FAILURE;
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post($hub.'/api/installs', [
                    'url' => $url,
                    'install_id' => PropertyDescriptor::installId(),
                    'descriptor' => $url.'/.well-known/doba.json',
                ]);
        } catch (Throwable $e) {
            $this->record($hotel, false, $e->getMessage());
            $this->error('Could not reach '.$hub.': '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->record($hotel, false, 'HTTP '.$response->status());
            $this->error($hub.' answered HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $this->record($hotel, true, null);
        $this->info('Announced to '.$hub.'.');

        return self::SUCCESS;
    }

    /**
     * Kept so the admin page can say when this last worked.
     *
     * A silent nightly job is one nobody notices has been failing since
     * March.
     */
    protected function record(HotelSettings $hotel, bool $ok, ?string $error): void
    {
        Setting::put('directory', 'last_announce', [
            'at' => CarbonImmutable::now()->toIso8601String(),
            'ok' => $ok,
            'error' => $error,
        ]);

        $hotel->refresh();
    }
}
