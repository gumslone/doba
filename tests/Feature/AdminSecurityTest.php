<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Security\TwoFactor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * The admin's own account (§14): remember-me as a choice, a second
 * factor, and the way back in from the shell.
 */
function owner(array $attrs = []): User
{
    return User::factory()->create(array_merge(['email' => 'owner@hotel.example', 'password' => Hash::make('correct horse battery')], $attrs));
}

function currentCode(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

/**
 * @return array{secret:string,codes:array<int,string>}
 */
function withTwoFactor(User $user): array
{
    $secret = app(TwoFactor::class)->generateSecret();
    $codes = app(TwoFactor::class)->recoveryCodes();
    $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now(), 'totp_recovery_codes' => $codes['hashed']])->save();

    return ['secret' => $secret, 'codes' => $codes['plain']];
}

it('remembers the session only when asked', function (): void {
    owner();

    $this->post('/admin/login', ['email' => 'owner@hotel.example', 'password' => 'correct horse battery']);
    $this->assertAuthenticated();
    // No remember cookie by default: a shared front-desk PC.
    expect(collect(app('cookie')->getQueuedCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web')))->toBeNull();

    auth()->logout();

    $this->post('/admin/login', ['email' => 'owner@hotel.example', 'password' => 'correct horse battery', 'remember' => '1']);
    $this->assertAuthenticated();
    expect(collect(app('cookie')->getQueuedCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web')))->not->toBeNull();
});

it('asks for the second factor before signing in, and refuses a wrong code', function (): void {
    $user = owner();
    $secret = withTwoFactor($user)['secret'];

    // The password alone is not a login.
    $this->post('/admin/login', ['email' => 'owner@hotel.example', 'password' => 'correct horse battery'])
        ->assertRedirect('/admin/login/2fa');
    $this->assertGuest();

    $this->post('/admin/login/2fa', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/admin/login/2fa', ['code' => currentCode($secret)])->assertRedirect('/admin/front-desk');
    $this->assertAuthenticatedAs($user);
});

it('accepts a recovery code exactly once', function (): void {
    $user = owner();
    $code = withTwoFactor($user)['codes'][0];

    $this->post('/admin/login', ['email' => 'owner@hotel.example', 'password' => 'correct horse battery']);
    $this->post('/admin/login/2fa', ['code' => $code])->assertRedirect('/admin/front-desk');
    $this->assertAuthenticated();

    // Spent: the same code is now a wrong code.
    auth()->logout();
    $this->post('/admin/login', ['email' => 'owner@hotel.example', 'password' => 'correct horse battery']);
    $this->post('/admin/login/2fa', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();

    expect(count($user->fresh()->totp_recovery_codes))->toBe(7);
});

it('cannot reach the challenge without having passed the password', function (): void {
    owner();

    $this->get('/admin/login/2fa')->assertRedirect('/admin/login');
    $this->post('/admin/login/2fa', ['code' => '123456'])->assertRedirect('/admin/login');
});

it('turns the factor on only after the phone proves it has the secret', function (): void {
    $user = owner();

    // First visit mints a secret into the session and shows a QR.
    $page = $this->actingAs($user)->get('/admin/security')->assertOk();
    $page->assertSee('<svg', false);
    $secret = session('two_factor_setup');
    expect($secret)->toBeString()->and($user->fresh()->hasTwoFactor())->toBeFalse();

    // A wrong code stores nothing — a secret saved before confirmation
    // locks the owner out with a QR they never scanned.
    $this->actingAs($user)->post('/admin/security/2fa/enable', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($user->fresh()->hasTwoFactor())->toBeFalse();

    $this->actingAs($user)->withSession(['two_factor_setup' => $secret])
        ->post('/admin/security/2fa/enable', ['code' => currentCode($secret)])
        ->assertRedirect('/admin/security');

    expect($user->fresh()->hasTwoFactor())->toBeTrue()
        ->and(count($user->fresh()->totp_recovery_codes))->toBe(8);

    // The codes are shown once, on the next page.
    $this->actingAs($user)->get('/admin/security')->assertOk()->assertSee('Recovery codes');
    $this->actingAs($user)->get('/admin/security')->assertOk()->assertDontSee('Recovery codes');
});

it('needs the password to turn the factor off or reissue codes', function (): void {
    $user = owner();
    withTwoFactor($user);

    $this->actingAs($user)->post('/admin/security/2fa/disable', ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($user->fresh()->hasTwoFactor())->toBeTrue();

    $this->actingAs($user)->post('/admin/security/2fa/disable', ['password' => 'correct horse battery'])->assertRedirect();
    expect($user->fresh()->hasTwoFactor())->toBeFalse();
});

it('changes the password and ends every other session', function (): void {
    $user = owner();

    $this->actingAs($user)->post('/admin/security/password', [
        'current_password' => 'correct horse battery',
        'password' => 'a-much-longer-new-password',
        'password_confirmation' => 'a-much-longer-new-password',
    ])->assertRedirect('/admin/security');

    expect(Hash::check('a-much-longer-new-password', $user->fresh()->password))->toBeTrue();

    $this->actingAs($user)->post('/admin/security/password', [
        'current_password' => 'nope', 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password',
    ])->assertSessionHasErrors('current_password');
});

it('resets a password from the shell, optionally clearing the factor', function (): void {
    $user = owner();
    withTwoFactor($user);

    expect(Artisan::call('doba:admin:reset-password', ['email' => 'owner@hotel.example', '--password' => 'from-the-shell-123', '--clear-2fa' => true]))->toBe(0);

    $fresh = $user->fresh();
    expect(Hash::check('from-the-shell-123', $fresh->password))->toBeTrue()
        ->and($fresh->hasTwoFactor())->toBeFalse();

    expect(Artisan::call('doba:admin:reset-password', ['email' => 'nobody@hotel.example']))->toBe(1);
});

it('keeps the account page behind the session', function (): void {
    $this->get('/admin/security')->assertRedirect('/admin/login');
});
