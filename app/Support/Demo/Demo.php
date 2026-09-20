<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Illuminate\Http\Request;

/**
 * The public demo (§22): a real install anybody may log into.
 *
 * A hotelier deciding whether to trust this software wants to stand
 * behind the desk, not read about it. So the demo hands out the admin
 * login on every page — which means everything a stranger could use to
 * hurt the next visitor, or the host, has to be off: content that shows
 * on the public site (a spam magnet on an indexed domain), outgoing mail,
 * outbound fetches, credentials, the updater. What stays on is the part
 * worth trying: the desk, bookings, rates, rooms, housekeeping, reports.
 *
 * Everything else heals itself: the whole install is rebuilt nightly.
 */
final class Demo
{
    /**
     * Admin sections whose writes stay enabled. Path prefixes under
     * /admin; anything not listed is read-only in a demo.
     */
    public const WRITABLE = [
        'logout',
        'front-desk',
        'bookings',
        'housekeeping',
        'rooms',
        'availability',
        'rate-plans',
        'promo-codes',
        'room-types',
        'extras',
        'reviews',
        'guests',
        'vouchers',
    ];

    /** Writes refused even inside a writable section. */
    public const BLOCKED = [
        'guests/*/erase',      // leaves the next visitor an empty guest book
        'enquiries/*/reply',   // sends mail
        'vouchers/instructions', // free text shown to the next visitor's buyer
    ];

    public static function enabled(): bool
    {
        return (bool) config('doba.demo.enabled', false);
    }

    public static function allows(Request $request): bool
    {
        if (! self::enabled() || $request->isMethodSafe()) {
            return true;
        }

        $path = trim((string) preg_replace('#^admin/?#', '', $request->path()), '/');

        foreach (self::BLOCKED as $pattern) {
            if ($request->is('admin/'.$pattern)) {
                return false;
            }
        }

        foreach (self::WRITABLE as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
