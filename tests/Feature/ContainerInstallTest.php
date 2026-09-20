<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Support\Install\EnvWriter;
use App\Support\Maintenance\HealthCheck;

/**
 * The two things a container (or any release-per-folder deploy) needs from
 * the application itself (§15): a symlinked .env that survives being
 * written, and an SQLite file that can live on the data volume.
 */
it('writes through a symlinked .env instead of replacing the link', function (): void {
    $dir = storage_path('framework/testing/envlink-'.bin2hex(random_bytes(4)));
    mkdir($dir.'/data', 0777, true);
    mkdir($dir.'/release', 0777, true);

    file_put_contents($dir.'/data/.env', "APP_NAME=Doba\nDOBA_CURRENCY=EUR\n");
    symlink($dir.'/data/.env', $dir.'/release/.env');

    (new EnvWriter($dir.'/release/.env'))->write(['DOBA_CURRENCY' => 'PLN']);

    // Still a link — the next release, or the next container, finds the
    // same file — and the value landed in the real one.
    expect(is_link($dir.'/release/.env'))->toBeTrue()
        ->and(file_get_contents($dir.'/data/.env'))->toContain('DOBA_CURRENCY=PLN')
        ->and(file_get_contents($dir.'/data/.env'))->toContain('APP_NAME=Doba')
        // No temporary file left beside either of them.
        ->and(glob($dir.'/data/.env.*.tmp'))->toBe([])
        ->and(glob($dir.'/release/.env.*.tmp'))->toBe([]);

    array_map('unlink', [$dir.'/release/.env', $dir.'/data/.env']);
    rmdir($dir.'/release');
    rmdir($dir.'/data');
    rmdir($dir);
});

it('still writes a plain .env exactly as before', function (): void {
    $path = storage_path('framework/testing/plain-'.bin2hex(random_bytes(4)).'.env');
    file_put_contents($path, "DOBA_CURRENCY=EUR\n");

    (new EnvWriter($path))->write(['DOBA_CURRENCY' => 'UAH']);

    expect(is_link($path))->toBeFalse()
        ->and(file_get_contents($path))->toContain('DOBA_CURRENCY=UAH');

    unlink($path);
});

it('lets a deployment put the SQLite file on its data volume', function (): void {
    // The default is the framework's own directory…
    expect(config('doba.install.sqlite_path'))->toBe(database_path('database.sqlite'));

    // …and the shipped container image points it at /data, where the
    // Dockerfile and the entrypoint agree the hotel's state lives.
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($dockerfile)->toContain('DOBA_SQLITE_PATH=/data/database.sqlite')
        ->and($dockerfile)->toContain('VOLUME /data')
        ->and($dockerfile)->toContain('ln -s /data/.env .env')
        ->and($entrypoint)->toContain('php artisan doba:update')
        ->and($entrypoint)->toContain('php artisan schedule:run')
        ->and($entrypoint)->toContain('php artisan queue:work');
});

it('forces https only where the hotel has said its address is https', function (): void {
    $force = AppServiceProvider::shouldForceHttps(...);

    // A first look on localhost in a container must reach the wizard, not
    // be redirected to a port that speaks no TLS…
    expect($force('production', 'http://localhost:8080'))->toBeFalse()
        ->and($force('production', 'http://192.168.1.20'))->toBeFalse()
        // …a live hotel never emits an http:// canonical…
        ->and($force('production', 'https://hotel.example'))->toBeTrue()
        // …and development is left alone either way.
        ->and($force('local', 'https://hotel.test'))->toBeFalse();
});

it('nags about plain http in production, but not on a local address', function (): void {
    $check = fn () => collect(app(HealthCheck::class)->all(deep: false))->firstWhere('key', 'https');

    config()->set('app.env', 'production');

    config()->set('app.url', 'http://hotel.example');
    expect($check()['status'])->toBe('warning');

    config()->set('app.url', 'http://localhost:8080');
    expect($check()['status'])->toBe('ok');

    config()->set('app.url', 'https://hotel.example');
    expect($check()['status'])->toBe('ok');
});
