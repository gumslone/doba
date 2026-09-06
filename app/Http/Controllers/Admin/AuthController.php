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
use Illuminate\Validation\ValidationException;

/**
 * The pre-Filament admin door. Filament replaces this whole area when it
 * lands (§1's decision table); until then the editing surface below needs
 * a lock on it, and this is the lock: session auth, throttled, nothing
 * clever.
 */
class AuthController extends Controller
{
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

        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        if ($user->hasTwoFactor()) {
            // The password was right; the session is not yet a login. Who
            // is halfway through is remembered server-side, never in a
            // form field, and the second step has its own throttle.
            $request->session()->put('two_factor', ['user' => $user->id, 'remember' => $remember]);

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
        if (! is_array($request->session()->get('two_factor'))) {
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
        $pending = $request->session()->get('two_factor');

        if (! is_array($pending)) {
            return redirect('/admin/login');
        }

        $request->validate(['code' => ['required', 'string', 'max:20']]);

        /** @var User|null $user */
        $user = User::query()->find($pending['user'] ?? 0);
        $code = (string) $request->input('code');

        $ok = $user !== null && $user->totp_secret !== null && (
            $twoFactor->verify($user->totp_secret, $code)
            || $user->useRecoveryCode($code)
        );

        if (! $ok) {
            throw ValidationException::withMessages(['code' => __('admin.two_factor_wrong')]);
        }

        $request->session()->forget('two_factor');
        Auth::login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended('/admin/front-desk');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}
