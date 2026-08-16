<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The §6 state machine.
 *
 * Inventory is released by the status being ENTERED, not by the status
 * being left: "only confirmed→cancelled releases inventory" is the trap
 * that leaks a unit forever when staff cancel a pending booking. Instead
 * every status declares how it consumes inventory, and BookingService
 * diffs the two sides of a transition. One rule, no leaks.
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * How this status holds inventory: 'held' (checkout-hold counters),
     * 'booked', or 'none'.
     */
    public function inventorySide(): string
    {
        return match ($this) {
            self::Pending => 'held',
            self::Confirmed, self::CheckedIn, self::CheckedOut, self::NoShow => 'booked',
            self::Cancelled => 'none',
        };
    }

    /**
     * @return array<int,self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::CheckedOut, self::Cancelled],
            self::CheckedOut, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
