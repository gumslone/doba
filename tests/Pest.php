<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Every canonical URL, hreflang href and sitemap <loc> is absolute, so the
 * assertions need a stable host. Without this they would pass against
 * whatever APP_URL the machine running them happens to have.
 */
function seoHost(): string
{
    return rtrim((string) config('app.url'), '/');
}
