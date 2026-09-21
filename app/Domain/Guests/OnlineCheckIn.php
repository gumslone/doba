<?php

declare(strict_types=1);

namespace App\Domain\Guests;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * When a guest may check in online, and what they are told once they have.
 *
 * The window opens a few days before arrival — early enough to do it from
 * the sofa, late enough that the details are current — and closes when the
 * stay is over. Only a confirmed booking qualifies: a hold is not a guest.
 */
final class OnlineCheckIn
{
    public static function enabled(): bool
    {
        return (bool) config('doba.features.online_checkin', false);
    }

    public static function isOpen(Booking $booking, ?CarbonImmutable $today = null): bool
    {
        if (! self::enabled() || ! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)) {
            return false;
        }

        $today ??= CarbonImmutable::today(config('doba.timezone'));
        $opens = $booking->check_in->subDays(max(0, (int) config('doba.checkin.open_days', 3)));

        return $today->gte($opens) && $today->lt($booking->check_out);
    }

    /**
     * Whose document details the form insists on: nobody's, only guests
     * from abroad, or everybody's. Laws differ; the hotel sets its own.
     */
    public static function needsDocument(?string $nationality): bool
    {
        return match ((string) config('doba.checkin.require_document', 'foreign')) {
            'all' => true,
            'none' => false,
            default => $nationality !== null && $nationality !== ''
                && strtoupper($nationality) !== strtoupper((string) config('doba.checkin.home_country', 'DE')),
        };
    }

    /**
     * The key-box code, the side entrance, where to park: shown only to a
     * guest who has checked in online, only around their arrival, and —
     * where the hotel wants it so — only once the stay is paid.
     */
    public static function showsInstructions(Booking $booking, ?CarbonImmutable $today = null): bool
    {
        if (! self::enabled() || $booking->registration === null) {
            return false;
        }

        if ((bool) config('doba.checkin.instructions_require_paid', false) && $booking->balance_due > 0) {
            return false;
        }

        $today ??= CarbonImmutable::today(config('doba.timezone'));

        return $today->gte($booking->check_in->subDay()) && $today->lt($booking->check_out)
            && in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true);
    }
}
