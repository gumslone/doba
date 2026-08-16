<?php

declare(strict_types=1);

use App\Models\RoomType;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The CSP / client-script contract
|--------------------------------------------------------------------------
|
| Written after a real failure: the §14 Content Security Policy forbids
| 'unsafe-eval', and Alpine evaluates every binding with new Function().
| The result was that the availability calendar, the click-to-load map and
| the admin's locale tabs all failed silently in the browser while the
| whole server-side suite stayed green — nothing here executes JavaScript.
|
| These tests cannot run a browser, so they guard the two invariants that
| made the failure possible: the policy must keep forbidding eval, and no
| template may reintroduce a framework that needs it.
|
*/

it('never grants unsafe-eval', function (): void {
    $csp = $this->get('/en')->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('unsafe-eval');
});

it('has no Alpine directives left in any template', function (): void {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $contents = $file->getContents();

        // x-data / x-show / x-for / x-text / x-model / x-if / x-cloak and
        // the @click shorthand all require runtime expression evaluation.
        if (preg_match('/\sx-(data|show|for|text|model|if|cloak|bind|on)\b|\s@click\s*=/', $contents)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'These templates use directives that need eval, which the CSP forbids: '.implode(', ', $offenders));
});

it('ships no expression-evaluating framework in the bundle', function (): void {
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    $dependencies = array_merge(
        $package['dependencies'] ?? [],
        $package['devDependencies'] ?? [],
    );

    expect(array_keys($dependencies))
        ->not->toContain('alpinejs')
        ->not->toContain('vue');
});

it('serves the calendar as inert markup that a script hydrates', function (): void {
    // The widget must carry its configuration in a data attribute rather
    // than an inline <script>, or the CSP would need a script-src
    // exception (or a nonce pipeline) it currently does not have.
    $roomType = RoomType::create([
        'code' => 'DBL', 'base_occupancy' => 2, 'max_occupancy' => 2,
        'default_rate' => 10000, 'total_units' => 2,
    ]);
    $roomType->translations()->create(['locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room']);

    $html = $this->get('/en/rooms/double-room')->assertOk()->getContent();

    expect($html)->toContain('data-doba-calendar')
        ->toContain('data-config=')
        ->toContain('data-cal-months');
});
