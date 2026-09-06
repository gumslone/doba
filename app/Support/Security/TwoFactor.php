<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Models\User;
use App\Support\Hotel\HotelSettings;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP for the admin session (§14).
 *
 * Standard RFC 6238, so any authenticator app works and there is
 * nothing to install on the phone that was not already there. The QR
 * code is rendered as inline SVG by pure PHP — no GD, no external image
 * request that would have to be allowed through the CSP.
 */
class TwoFactor
{
    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUri(User $user, string $secret): string
    {
        $issuer = app(HotelSettings::class)->name ?: 'Doba';

        return $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $renderer = new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->otpauthUri($user, $secret));
    }

    /**
     * One step of drift either way: a phone whose clock is thirty seconds
     * out is a phone, not an attacker.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $code, 1) === true;
    }

    /**
     * Eight codes, shown once, stored hashed. Each is spent on use.
     *
     * @return array{plain:array<int,string>,hashed:array<int,string>}
     */
    public function recoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < 8; $i++) {
            $plain[] = strtoupper(Str::random(5).'-'.Str::random(5));
        }

        return [
            'plain' => $plain,
            'hashed' => array_map(static fn (string $c): string => Hash::make($c), $plain),
        ];
    }
}
