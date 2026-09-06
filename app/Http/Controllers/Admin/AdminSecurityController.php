<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\TwoFactor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The admin's own account (§14): password, and the second factor.
 *
 * The admin session is the crown jewels — it edits what the public
 * site prints. A second factor turns a phished password into a
 * nuisance instead of a takeover, and this is where it is switched on.
 */
class AdminSecurityController extends Controller
{
    public function edit(Request $request, TwoFactor $twoFactor): View
    {
        /** @var User $user */
        $user = $request->user();

        // A secret is minted and held in the session until the first
        // code proves the phone has it; only then is it stored. A secret
        // saved before confirmation locks the owner out of their own
        // account with a QR they never scanned.
        $pending = $request->session()->get('two_factor_setup');

        if (! $user->hasTwoFactor() && ! is_string($pending)) {
            $pending = $twoFactor->generateSecret();
            $request->session()->put('two_factor_setup', $pending);
        }

        return view('admin.security.index', [
            'user' => $user,
            'enabled' => $user->hasTwoFactor(),
            'pendingSecret' => $user->hasTwoFactor() ? null : $pending,
            'qr' => $user->hasTwoFactor() || ! is_string($pending) ? null : $twoFactor->qrSvg($user, $pending),
            'recoveryCodes' => $request->session()->get('two_factor_recovery'),
            'recoveryLeft' => is_array($user->totp_recovery_codes) ? count($user->totp_recovery_codes) : 0,
        ]);
    }

    public function enable(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $secret = $request->session()->get('two_factor_setup');

        $request->validate(['code' => ['required', 'string', 'max:10']]);

        if (! is_string($secret) || ! $twoFactor->verify($secret, (string) $request->input('code'))) {
            return back()->withErrors(['code' => __('admin.two_factor_wrong')]);
        }

        $codes = $twoFactor->recoveryCodes();

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => CarbonImmutable::now(),
            'totp_recovery_codes' => $codes['hashed'],
        ])->save();

        $request->session()->forget('two_factor_setup');
        // Shown once, on the next page load, then gone from the session.
        $request->session()->flash('two_factor_recovery', $codes['plain']);

        return redirect('/admin/security')->with('saved', __('admin.two_factor_enabled'));
    }

    /**
     * Switching the factor off needs the password: a session left open
     * on a desk must not be able to weaken the account it is in.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'totp_recovery_codes' => null,
        ])->save();

        return redirect('/admin/security')->with('saved', __('admin.two_factor_disabled'));
    }

    public function regenerateCodes(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactor()) {
            return redirect('/admin/security');
        }

        $codes = $twoFactor->recoveryCodes();
        $user->forceFill(['totp_recovery_codes' => $codes['hashed']])->save();
        $request->session()->flash('two_factor_recovery', $codes['plain']);

        return redirect('/admin/security')->with('saved', __('admin.recovery_regenerated'));
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        // Every other session for this account ends: a changed password
        // is usually a suspected one.
        auth()->logoutOtherDevices($validated['password']);

        return redirect('/admin/security')->with('saved', __('admin.password_changed'));
    }
}
