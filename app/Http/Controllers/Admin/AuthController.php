<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\TwoFactor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The pre-Filament admin door. Filament replaces this whole area when it
 * lands (§1's decision table); until then the editing surface below needs
 * a lock on it, and this is the lock: session auth, throttled, nothing
 * clever.
 */
class AuthController extends Controller
{
    private const LOGIN_ATTEMPTS = 20;

    private const CODE_ATTEMPTS = 8;

    private const LOCKOUT_SECONDS = 900;

    /** How long a right password waits for its code. */
    private const CODE_WINDOW_SECONDS = 600;

    public function showLogin(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Remember-me is a choice, not a default: on a shared front-desk
        // PC a long-lived cookie is everyone's session, not the owner's.
        $remember = $request->boolean('remember');

        // Per ACCOUNT, on top of the route's per-IP throttle. An address
        // is something an attacker has many of; the account they are
        // guessing at is one. Twenty wrong passwords in a quarter of an
        // hour is far beyond a tired owner and useless against a
        // twelve-character password.
        $key = 'admin-login:'.sha1(mb_strtolower((string) $credentials['email']));

        if (RateLimiter::tooManyAttempts($key, self::LOGIN_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($key);

        /** @var User $user */
        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        if ($user->hasTwoFactor()) {
            // The password was right; the session is not yet a login. Who
            // is halfway through is remembered server-side, never in a
            // form field, and the second step has its own throttle.
            $request->session()->put('two_factor', [
                'user' => $user->id,
                'remember' => $remember,
                // A right password is good for a few minutes of typing a
                // code, not for the life of the session.
                'at' => now()->getTimestamp(),
            ]);

            return redirect('/admin/login/2fa');
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        // The front desk, not the CMS: it is the screen a hotel opens
        // in the morning. `intended` still wins, so a deep link survives
        // the login it triggered.
        return redirect()->intended('/admin/front-desk');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if ($this->pendingSecondFactor($request) === null) {
            return redirect('/admin/login');
        }

        return view('admin.login-2fa');
    }

    /**
     * The second factor: a six-digit code, or one of the recovery codes
     * shown once when the factor was enabled.
     */
    public function verify(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $pending = $this->pendingSecondFactor($request);

        if ($pending === null) {
            return redirect('/admin/login');
        }

        $request->validate(['code' => ['required', 'string', 'max:20']]);

        /** @var User|null $user */
        $user = User::query()->find($pending['user'] ?? 0);
        $code = (string) $request->input('code');

        // Per account again: six digits is a million codes, and without a
        // cap that does not depend on the caller's address this step is a
        // few hours of guessing for anyone holding a stolen password.
        $key = 'admin-2fa:'.($user === null ? 0 : $user->id);

        if (RateLimiter::tooManyAttempts($key, self::CODE_ATTEMPTS)) {
            $request->session()->forget('two_factor');

            return redirect('/admin/login')->withErrors([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $ok = $user !== null && $user->totp_secret !== null && (
            $twoFactor->verifyOnce($user, $code)
            || $user->useRecoveryCode($code)
        );

        if (! $ok) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages(['code' => __('admin.two_factor_wrong')]);
        }

        RateLimiter::clear($key);
        $request->session()->forget('two_factor');
        Auth::login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended('/admin/front-desk');
    }

    /**
     * The half-finished login, while it is still fresh.
     *
     * @return array{user?:int,remember?:bool,at?:int}|null
     */
    protected function pendingSecondFactor(Request $request): ?array
    {
        $pending = $request->session()->get('two_factor');

        if (! is_array($pending)) {
            return null;
        }

        if ((int) ($pending['at'] ?? 0) < now()->getTimestamp() - self::CODE_WINDOW_SECONDS) {
            $request->session()->forget('two_factor');

            return null;
        }

        return $pending;
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}
