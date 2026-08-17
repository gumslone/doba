<?php

declare(strict_types=1);

use App\Support\Install\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // The suite is a hotel that is already running. Without this,
        // EnsureInstalled would send every request in every test to the
        // wizard — which is exactly what it should do to a fresh copy,
        // and exactly what these tests are not about.
        //
        // The markers live under storage/framework/testing so a test run
        // never writes an installed.lock into the developer's own copy.
        $lock = storage_path('framework/testing/installed.lock');

        File::ensureDirectoryExists(dirname($lock));
        config([
            'doba.install.lock_path' => $lock,
            'doba.install.token_path' => storage_path('framework/testing/install-token.txt'),
        ]);

        File::put($lock, 'testing');

        DB::table('installations')->insert([
            'steps_completed' => json_encode(Installer::STEPS),
            'locale' => 'en',
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })
    ->in('Feature');

/**
 * Every canonical URL, hreflang href and sitemap <loc> is absolute, so the
 * assertions need a stable host. Without this they would pass against
 * whatever APP_URL the machine running them happens to have.
 */
function seoHost(): string
{
    return rtrim((string) config('app.url'), '/');
}

/**
 * Pull every application/ld+json block out of a response and decode it.
 *
 * Decoding rather than string-matching is the point: invalid JSON in a
 * JSON-LD block is silently ignored by Google, so a test that greps for
 * "HotelRoom" passes on markup that no crawler can read.
 *
 * @return array<int,array<string,mixed>>
 */
function jsonLdBlocks(string $html): array
{
    preg_match_all(
        '#<script type="application/ld\+json">(.*?)</script>#s',
        $html,
        $matches
    );

    return array_map(static function (string $json): array {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toBeArray();

        return $decoded;
    }, $matches[1]);
}
