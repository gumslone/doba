<?php

declare(strict_types=1);

/**
 * The single-file web installer (scripts/doba-installer.php).
 *
 * It runs on servers that have nothing of this codebase yet, so it can
 * use none of it — but its logic is still ours to break, and the release
 * workflow ships it. Under CLI the file defines the class and returns,
 * which is what makes it testable at all.
 */
beforeEach(function (): void {
    require_once base_path('scripts/doba-installer.php');

    $this->dir = sys_get_temp_dir().'/doba-webinstall-'.bin2hex(random_bytes(4));
    mkdir($this->dir);

    $this->installer = new DobaWebInstaller($this->dir);
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

it('finds the newest release even when every release is a pre-release', function (): void {
    // The trap this guards: /releases/latest only returns FULL releases,
    // and every 0.x Doba release is deliberately marked pre-release — an
    // installer built on that endpoint tells every early hotelier there
    // is nothing to install.
    $picked = $this->installer->pickRelease([
        [
            'tag_name' => 'v0.2.0',
            'prerelease' => true,
            'assets' => [
                ['browser_download_url' => 'https://example.com/doba-v0.2.0.tar.gz'],
                ['browser_download_url' => 'https://example.com/doba-v0.2.0.tar.gz.sha256'],
            ],
        ],
        [
            'tag_name' => 'v0.1.0',
            'prerelease' => true,
            'assets' => [
                ['browser_download_url' => 'https://example.com/doba-v0.1.0.tar.gz'],
                ['browser_download_url' => 'https://example.com/doba-v0.1.0.tar.gz.sha256'],
            ],
        ],
    ]);

    expect($picked['name'])->toBe('v0.2.0')
        ->and($picked['tarball'])->toContain('v0.2.0.tar.gz');
});

it('skips a release whose assets are still uploading', function (): void {
    // The moment between "release created" and "assets attached" is real:
    // the workflow creates the release first. A tarball with no checksum
    // must be passed over, not installed unverified.
    $picked = $this->installer->pickRelease([
        ['tag_name' => 'v0.2.0', 'assets' => [
            ['browser_download_url' => 'https://example.com/doba-v0.2.0.tar.gz'],
        ]],
        ['tag_name' => 'v0.1.0', 'assets' => [
            ['browser_download_url' => 'https://example.com/doba-v0.1.0.tar.gz'],
            ['browser_download_url' => 'https://example.com/doba-v0.1.0.tar.gz.sha256'],
        ]],
    ]);

    expect($picked['name'])->toBe('v0.1.0');
});

it('verifies a checksum in the format sha256sum writes', function (): void {
    $file = $this->dir.'/blob';
    file_put_contents($file, 'contents');

    $hash = hash('sha256', 'contents');

    // "hash  filename\n" — exactly what the release workflow produces.
    expect($this->installer->checksumMatches($file, $hash."  doba-v0.1.0.tar.gz\n"))->toBeTrue()
        ->and($this->installer->checksumMatches($file, str_repeat('0', 64)."  doba-v0.1.0.tar.gz\n"))->toBeFalse()
        ->and($this->installer->checksumMatches($file, "not-a-hash\n"))->toBeFalse();
});

it('extracts the tarball and flattens doba/ up around itself', function (): void {
    $stage = $this->dir.'/stage';
    mkdir($stage.'/doba/public', 0777, true);
    file_put_contents($stage.'/doba/artisan', '#!/usr/bin/env php');
    file_put_contents($stage.'/doba/env.example', "APP_KEY=\n");
    file_put_contents($stage.'/doba/public/index.php', '<?php');

    $tarball = $this->dir.'/release.tar.gz';
    exec(sprintf('tar -czf %s -C %s doba', escapeshellarg($tarball), escapeshellarg($stage)), $out, $code);
    expect($code)->toBe(0);

    exec('rm -rf '.escapeshellarg($stage));

    $this->installer->extract($tarball);

    expect(is_file($this->dir.'/artisan'))->toBeTrue()
        ->and(is_file($this->dir.'/public/index.php'))->toBeTrue()
        ->and(is_dir($this->dir.'/doba'))->toBeFalse()
        ->and($this->installer->alreadyExtracted())->toBeTrue();
});

it('writes the smallest .env that boots, in production mode', function (): void {
    // The example ships development defaults; a hotel must not boot with
    // APP_DEBUG=true, because debug pages print configuration to whoever
    // causes an error.
    file_put_contents($this->dir.'/env.example', "APP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\n");

    $this->installer->writeEnv('https://hotel.example/');

    $env = (string) file_get_contents($this->dir.'/.env');

    expect($env)->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('APP_URL=https://hotel.example')
        ->and($env)->toMatch('/APP_KEY=base64:[A-Za-z0-9+\/]{43}=/')
        ->and($env)->toContain('DB_CONNECTION=sqlite');
});

it('requires the token file next to itself, like the wizard requires its own', function (): void {
    $token = $this->installer->ensureToken();

    expect(strlen($token))->toBe(40)
        ->and($this->installer->tokenValid($token))->toBeTrue()
        ->and($this->installer->tokenValid('guessed'))->toBeFalse()
        ->and($this->installer->tokenValid(''))->toBeFalse()
        // Re-ensuring must not rotate it out from under an open page.
        ->and($this->installer->ensureToken())->toBe($token);
});

it('denies the paths that must never be served in the docroot fallback', function (): void {
    $htaccess = $this->installer->guardHtaccess();

    // The fallback exists for hosts that cannot point the docroot at
    // public/ — which means .env sits inside the docroot, and these
    // rules are all that stands between it and the internet.
    foreach (['.env', 'storage', 'vendor', 'database', 'RewriteRule ^(.*)$ public/$1'] as $must) {
        expect($htaccess)->toContain($must);
    }
});
