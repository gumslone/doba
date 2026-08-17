<?php

declare(strict_types=1);

use App\Models\Availability;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Install\EnvWriter;
use App\Support\Install\Installer;
use App\Support\Install\Requirements;
use App\Support\Install\RoomBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->installer = app(Installer::class);

    // A fresh copy: neither marker present.
    File::delete($this->installer->lockPath());
    File::delete($this->installer->tokenPath());
    DB::table('installations')->delete();
});

afterEach(function (): void {
    File::delete($this->installer->lockPath());
    File::delete($this->installer->tokenPath());
});

it('sends an uninstalled copy to the wizard from anywhere', function (): void {
    foreach (['/', '/en', '/en/rooms', '/admin/login'] as $path) {
        $this->get($path)->assertRedirect('/install');
    }

    // The health check still answers: an install that reports itself down
    // for its whole duration gets restarted half way through by the thing
    // watching it.
    $this->get('/up')->assertOk();
});

it('404s the installer once installed, rather than saying so', function (): void {
    $this->installer->finish();

    // Not a redirect and not an "already installed" page: a scanner
    // walking the internet for /install should learn nothing.
    $this->get('/install')->assertNotFound();
    $this->get('/install/database')->assertNotFound();
    $this->post('/install/owner', [])->assertNotFound();
});

it('demands the token before any step', function (): void {
    $this->get('/install')->assertOk()->assertSee(__('install.token_title'));

    // The path is shown; the value never is, or the gate would be
    // decorative.
    $this->get('/install')->assertSee($this->installer->tokenPath())
        ->assertDontSee($this->installer->token());

    foreach (['language', 'database', 'owner'] as $step) {
        $this->get('/install/'.$step)->assertRedirect('/install');
        $this->post('/install/'.$step, [])->assertRedirect('/install');
    }

    $this->post('/install', ['token' => 'not-the-token'])->assertSessionHasErrors('token');

    $this->post('/install', ['token' => $this->installer->token()])
        ->assertRedirect('/install/language');
});

it('writes the token on first load, so a fresh clone is never briefly open', function (): void {
    expect(File::exists($this->installer->tokenPath()))->toBeFalse();

    $this->get('/install')->assertOk();

    expect(File::exists($this->installer->tokenPath()))->toBeTrue()
        ->and(strlen($this->installer->token()))->toBe(32);
});

it('refuses to skip ahead to a step that depends on an earlier one', function (): void {
    $this->withSession(['install_token_ok' => true]);

    // The owner step writes a user into a database the database step
    // creates.
    $this->get('/install/owner')->assertRedirect('/install/language');
    $this->get('/install/rooms')->assertRedirect('/install/language');
});

it('blocks on unmet requirements with no way past', function (): void {
    $this->withSession(['install_token_ok' => true]);

    $unmet = Mockery::mock(Requirements::class);
    $unmet->shouldReceive('all')->andReturn([
        ['name' => 'intl', 'ok' => false, 'detail' => 'missing', 'fix' => 'Enable intl.'],
    ]);
    $unmet->shouldReceive('satisfied')->andReturnFalse();

    $this->app->instance(Requirements::class, $unmet);

    $this->get('/install/requirements')->assertOk()->assertSee('Enable intl.');

    // No "continue anyway": the hotelier who clicks past a missing intl
    // is not the one who can diagnose the site that half-works a month
    // later.
    $this->post('/install/requirements', [])->assertSessionHasErrors('requirements');

    expect(app(Installer::class)->completedSteps())->not->toContain('requirements');
});

it('runs the whole wizard and lands on a working hotel', function (): void {
    $this->withSession(['install_token_ok' => true]);

    $this->post('/install/language', ['locale' => 'en'])->assertRedirect('/install/requirements');
    $this->post('/install/requirements', [])->assertRedirect('/install/database');

    // The suite is already migrated, so the database step is exercised
    // through its own connection test and marked done rather than
    // re-pointing the running app at a new file.
    $this->installer->markComplete('database');

    $this->post('/install/hotel', [
        'name' => 'Hotel Bergblick',
        'email' => 'stay@bergblick.example',
        'phone' => '+49 8022 111111',
        'street' => 'Seestraße 1',
        'postal_code' => '83700',
        'city' => 'Rottach-Egern',
        'country' => 'DE',
        'timezone' => 'Europe/Berlin',
        'currency' => 'eur',
        'checkin_from' => '14:00',
        'checkout_until' => '11:00',
    ])->assertRedirect('/install/owner');

    // Asserted on the rows, not on the cached settings object: that
    // object is per-request and refreshes on the NEXT request, which is
    // exactly the staleness the RouteMatched hook exists to prevent.
    expect(Setting::query()->where('group', 'general')->where('key', 'name')->value('value'))
        ->toContain('Hotel Bergblick')
        ->and(Setting::query()->where('group', 'contact')->where('key', 'city')->exists())->toBeTrue();

    $this->post('/install/owner', [
        'name' => 'Oleg',
        'email' => 'Owner@Bergblick.example',
        'password' => 'correct-horse-battery-7',
        'password_confirmation' => 'correct-horse-battery-7',
    ])->assertRedirect('/install/rooms');

    // Stored lower-cased, so signing in with the address as typed works.
    expect(User::query()->where('email', 'owner@bergblick.example')->exists())->toBeTrue();

    $this->post('/install/rooms', [
        'rooms' => [
            ['name' => 'Double room', 'units' => 6, 'occupancy' => 2, 'price' => '95.00'],
            ['name' => '', 'units' => 2, 'occupancy' => 2, 'price' => '80.00'],
        ],
    ])->assertRedirect('/install/finish');

    $double = RoomType::query()->where('code', 'DOUBLE_ROOM')->sole();

    expect($double->total_units)->toBe(6)
        // Entered in whole currency, stored in minor units (§5).
        ->and($double->default_rate)->toBe(9500)
        ->and($double->t('name'))->toBe('Double room')
        // The empty row was skipped, not created as a nameless room type.
        ->and(RoomType::query()->count())->toBe(1);

    // A hotelier who finishes and sees an empty calendar concludes the
    // software is broken.
    expect(Availability::query()->where('room_type_id', $double->id)->count())
        ->toBeGreaterThan(300);

    $this->post('/install/finish', [])->assertRedirect('/admin/front-desk');

    // Both markers, and signed in as the owner they just created.
    expect($this->installer->isInstalled())->toBeTrue()
        ->and($this->installer->hasLock())->toBeTrue()
        ->and($this->installer->hasRecord())->toBeTrue()
        ->and(File::exists($this->installer->tokenPath()))->toBeFalse();

    $this->assertAuthenticated();

    // And the wizard is gone.
    $this->get('/install')->assertNotFound();
});

it('resumes where it left off rather than starting again', function (): void {
    $this->withSession(['install_token_ok' => true]);

    $this->post('/install/language', ['locale' => 'en']);
    $this->post('/install/requirements', []);
    $this->installer->markComplete('database');
    $this->installer->markComplete('hotel');

    // A browser crash at step 5 resumes at step 5, not at the beginning
    // and not half way through with the first steps applied twice.
    expect($this->installer->currentStep())->toBe('owner');

    $this->get('/install')->assertRedirect('/install/owner');
});

it('opens in repair mode when the two markers disagree', function (): void {
    // A deploy rsynced storage/ and took the lock file with it.
    $this->installer->finish();
    File::delete($this->installer->lockPath());

    expect($this->installer->needsRepair())->toBeTrue()
        ->and($this->installer->isInstalled())->toBeFalse();

    $this->get('/install')
        ->assertOk()
        ->assertSee(__('install.repair_title'))
        // Emphatically not an offer to install over a live database.
        ->assertDontSee(__('install.token_label'));
});

it('rejects a weak or breached owner password', function (): void {
    $this->withSession(['install_token_ok' => true]);
    $this->installer->markComplete('language');
    $this->installer->markComplete('requirements');
    $this->installer->markComplete('database');
    $this->installer->markComplete('hotel');

    foreach (['short', 'password123456', 'aaaaaaaaaaaaaa'] as $weak) {
        $this->post('/install/owner', [
            'name' => 'Oleg',
            'email' => 'owner@example.com',
            'password' => $weak,
            'password_confirmation' => $weak,
        ])->assertSessionHasErrors('password');
    }

    expect(User::query()->where('email', 'owner@example.com')->exists())->toBeFalse();
});

it('builds a hotel from a template', function (): void {
    $created = (new RoomBuilder)->fromTemplate('bnb');

    expect($created)->toBe(3)
        // Cast: MySQL hands SUM() back as a string, SQLite as an int.
        ->and((int) RoomType::query()->sum('total_units'))->toBe(11);
});

it('writes a .env without destroying it', function (): void {
    $path = storage_path('framework/testing/env-test');

    File::put($path, <<<'ENV'
    # A comment somebody wrote
    APP_NAME=Doba
    APP_KEY=base64:abc

    # The database
    DB_CONNECTION=sqlite
    # DB_HOST=127.0.0.1
    ENV);

    (new EnvWriter($path))->write([
        'DB_CONNECTION' => 'mysql',
        // The value that breaks a naive writer: a `#` starts a comment
        // and a space ends the value, so the app would authenticate with
        // half a password and report the credentials as wrong.
        'DB_PASSWORD' => 'pa ss#word"$x',
        'DB_HOST' => '10.0.0.5',
    ]);

    $written = File::get($path);

    expect($written)
        // Comments and blank lines are exactly where they were.
        ->toContain('# A comment somebody wrote')
        ->toContain('# The database')
        // A commented-out key is a comment, not a key: it is not replaced.
        ->toContain('# DB_HOST=127.0.0.1')
        ->toContain('DB_CONNECTION=mysql')
        ->toContain('APP_NAME=Doba');

    // And the awkward value survives a round trip through the parser.
    $parsed = Dotenv\Dotenv::parse($written);

    expect($parsed['DB_PASSWORD'])->toBe('pa ss#word"$x')
        ->and($parsed['DB_HOST'])->toBe('10.0.0.5')
        ->and($parsed['DB_CONNECTION'])->toBe('mysql');

    File::delete($path);
});

it('refuses to write a .env it cannot write', function (): void {
    expect(fn () => (new EnvWriter('/nowhere/at/all/.env'))->write(['A' => 'b']))
        ->toThrow(RuntimeException::class);
});
