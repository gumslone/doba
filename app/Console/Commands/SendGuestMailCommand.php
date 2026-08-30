<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Mail\PostStay;
use App\Mail\PreArrival;
use App\Models\Booking;
use App\Support\Mail\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * The lifecycle mail nobody has to remember (§13).
 *
 * Runs daily off the scheduler. Selection is stamp-driven: each mail is
 * marked on the booking the moment it is queued, and the query selects
 * on the stamp being NULL — so a run that dies halfway and reruns picks
 * up the unmailed remainder and cannot mail anybody twice.
 *
 * The date windows are half-open on purpose. Pre-arrival goes to every
 * unmailed stay arriving within the next N days, not arriving in
 * exactly N: a booking made the day before arrival, or a scheduler that
 * was down on the one day the equality would have matched, still gets
 * its mail — late beats never, and the stamp keeps late from becoming
 * twice.
 */
class SendGuestMailCommand extends Command
{
    protected $signature = 'doba:guest-mail {--dry-run : Say who would be mailed, and mail nobody}';

    protected $description = 'Send pre-arrival and post-stay mail to guests';

    public function handle(MailSettings $mail): int
    {
        if (! $mail->isConfirmed()) {
            // The rule the mail screen is built around: outgoing mail is
            // broken until a human says a test message arrived. A nightly
            // job that silently "sent" hundreds of messages into a
            // misconfigured transport is that rule's worst enemy.
            $this->warn('Outgoing mail is not confirmed (Admin → Mail), so no guest mail was sent.');

            return self::SUCCESS;
        }

        // The hotel's today, not the server's: "arriving in three days"
        // is a fact about the hotel's calendar, and every other business
        // date in this codebase is already counted in that frame.
        $today = CarbonImmutable::today(config('doba.timezone'));
        $sent = 0;

        $days = (int) config('doba.guest_mail.pre_arrival_days');

        if ($days > 0) {
            $arriving = Booking::query()
                ->with('guest')
                ->whereNull('pre_arrival_sent_at')
                ->where('status', BookingStatus::Confirmed)
                // >= today, so a stay booked yesterday for tomorrow is
                // still greeted rather than silently skipped.
                ->whereBetween('check_in', [$today->toDateString(), $today->addDays($days)->toDateString()])
                ->get();

            foreach ($arriving as $booking) {
                $sent += $this->dispatch($booking, 'pre_arrival_sent_at', new PreArrival($booking));
            }
        }

        if ((bool) config('doba.guest_mail.post_stay')) {
            $departed = Booking::query()
                ->with('guest')
                ->whereNull('post_stay_sent_at')
                // CheckedOut is the desk doing its job; Confirmed with the
                // check-out date behind us is the desk not using the front
                // desk screen — still a real guest who really left.
                ->whereIn('status', [BookingStatus::CheckedOut, BookingStatus::Confirmed])
                ->where('check_out', '<', $today->toDateString())
                // A week, not forever: a hotel switching this on after a
                // busy season must not thank last month in one avalanche.
                ->where('check_out', '>=', $today->subDays(7)->toDateString())
                ->get();

            foreach ($departed as $booking) {
                $sent += $this->dispatch($booking, 'post_stay_sent_at', new PostStay($booking));
            }
        }

        $this->info(($this->option('dry-run') ? 'Would send ' : 'Queued ').$sent.' message(s).');

        return self::SUCCESS;
    }

    protected function dispatch(Booking $booking, string $stamp, PreArrival|PostStay $mailable): int
    {
        $email = $booking->guest?->email;

        if ($email === null || $email === '') {
            return 0;   // an iCal-imported stay may have no address at all
        }

        if ($booking->guest->isAnonymised()) {
            // Checked out on Monday, erased on Tuesday: the thank-you run
            // on Wednesday must find nobody. Mailing an anonymised
            // address is the erasure not having happened.
            return 0;
        }

        if ($this->option('dry-run')) {
            $this->line(sprintf('  %s -> %s (%s)', $booking->reference, $email, $stamp));

            return 1;
        }

        // Stamped BEFORE queueing. If the stamp write fails nothing has
        // been sent; if the queue push fails the stamp says sent when it
        // was not — and of the two lies, a guest missing one courtesy
        // mail is recoverable, a guest mailed nightly forever is a spam
        // report against the hotel's own domain.
        $booking->forceFill([$stamp => CarbonImmutable::now()])->save();

        Mail::to($email)->queue($mailable);

        return 1;
    }
}
