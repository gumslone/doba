<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;

class ReleaseExpiredHoldsCommand extends Command
{
    protected $signature = 'holds:release';

    protected $description = 'Cancel pending bookings whose checkout hold has expired and release their inventory';

    /**
     * Runs every minute (§15). The whole release goes through the same
     * BookingService transition as a staff cancellation — decrementing
     * `held` and stamping `released_at` under the same lock — so there is
     * exactly one code path that releases inventory, and it is the one the
     * tests already pin down.
     */
    public function handle(BookingService $bookings): int
    {
        $expired = Booking::query()
            ->where('status', BookingStatus::Pending)
            ->whereHas('holds', static fn ($query) => $query
                ->whereNull('released_at')
                ->where('expires_at', '<=', now()))
            ->get();

        foreach ($expired as $booking) {
            $bookings->transition($booking, BookingStatus::Cancelled, 'Hold expired');
        }

        if ($expired->isNotEmpty()) {
            $this->info("Released {$expired->count()} expired holds.");
        }

        return self::SUCCESS;
    }
}
