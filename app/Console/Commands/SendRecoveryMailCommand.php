<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Availability\AvailabilityService;
use App\Enums\BookingStatus;
use App\Mail\CheckoutReminder;
use App\Models\Booking;
use App\Support\Mail\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Remind the guest who did not finish (§13).
 *
 * Off by default, deliberately. A reminder to somebody who did not
 * complete a purchase is, in several countries, advertising that needs
 * consent — a hotel switches this on knowing its own law, not because a
 * default chose for it.
 *
 * When it is on, the rules are the ones a good receptionist would apply:
 * only a booking the GUEST started on the website (never a desk, API or
 * portal booking); only once; only after the hold has expired by itself,
 * never after the guest or the hotel cancelled; not if they came back
 * and booked anyway; not for a stay already in the past; and not unless
 * the room can still actually be had — a reminder that ends in "sold
 * out" is worse than silence.
 */
class SendRecoveryMailCommand extends Command
{
    /** The reason ReleaseExpiredHoldsCommand writes; the only kind of cancellation this follows up. */
    public const EXPIRED = 'Hold expired';

    protected $signature = 'doba:recovery-mail {--dry-run : Say who would be mailed, and mail nobody}';

    protected $description = 'Remind guests whose unfinished booking expired (off unless DOBA_MAIL_RECOVERY=true)';

    public function handle(MailSettings $mail, AvailabilityService $availability): int
    {
        if (! (bool) config('doba.guest_mail.recovery')) {
            return self::SUCCESS;
        }

        if (! $mail->isConfirmed()) {
            $this->warn('Outgoing mail is not confirmed (Admin → Mail), so no reminder was sent.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $after = max(10, (int) config('doba.guest_mail.recovery_after_minutes', 60));
        $today = CarbonImmutable::today(config('doba.timezone'))->toDateString();
        $sent = 0;

        $candidates = Booking::query()
            ->with(['guest', 'rooms.roomType.translations'])
            ->where('status', BookingStatus::Cancelled)
            ->where('cancellation_reason', self::EXPIRED)
            ->where('source', 'direct')
            ->whereNull('recovery_sent_at')
            ->where('check_in', '>=', $today)
            ->whereBetween('cancelled_at', [$now->subDay(), $now->subMinutes($after)])
            ->get();

        foreach ($candidates as $booking) {
            $guest = $booking->guest;
            $room = $booking->rooms->first();

            if ($guest === null || $guest->isAnonymised() || $guest->email === '' || str_ends_with($guest->email, '@no-email.invalid') || $room?->roomType === null) {
                continue;
            }

            // They came back and booked: the reminder would be noise.
            $cameBack = Booking::query()
                ->where('guest_id', $guest->id)
                ->where('id', '!=', $booking->id)
                ->where('status', '!=', BookingStatus::Cancelled)
                ->where('created_at', '>=', $booking->created_at)
                ->exists();

            if ($cameBack) {
                $booking->forceFill(['recovery_sent_at' => $now])->save();   // settled; never look again

                continue;
            }

            if (! $availability->isBookable($room->roomType, $booking->check_in, $booking->check_out, $booking->rooms->count(), $booking->adults, $booking->children)) {
                continue;   // not stamped: a cancellation elsewhere may free it within the day
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  %s -> %s', $booking->reference, $guest->email));
                $sent++;

                continue;
            }

            // Stamped before queueing, for the reason every guest mail is:
            // a guest missing one reminder is nothing, a guest reminded
            // every ten minutes is a spam report against the hotel.
            $booking->forceFill(['recovery_sent_at' => $now])->save();

            Mail::to($guest->email)->queue(new CheckoutReminder($booking));
            $sent++;
        }

        if ($sent > 0) {
            $this->info(($this->option('dry-run') ? 'Would send ' : 'Queued ').$sent.' reminder(s).');
        }

        return self::SUCCESS;
    }
}
