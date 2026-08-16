<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
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
