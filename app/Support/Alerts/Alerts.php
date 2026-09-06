<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Mail\SystemAlert;
use App\Support\Hotel\HotelSettings;
use App\Support\Mail\MailSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Telling the hotelier that something broke (§15).
 *
 * Every scheduled job in this system logged its failures, and on a
 * shared host nobody reads a log. A backup that has silently failed
 * since March is a hotel that believes it has copies; a reconciler that
 * corrects drift every night is a bug nobody knows about. So the
 * failures that matter go to the one place a hotelier looks: their
 * inbox.
 *
 * Two guards keep this from becoming noise. Nothing is sent while
 * outgoing mail is unconfirmed — an alert about a broken system through
 * a broken mail setup is two silences, not one. And the same subject is
 * sent at most once an hour, so a queue that fails a thousand jobs
 * produces one message, not a thousand.
 */
class Alerts
{
    public const THROTTLE_SECONDS = 3600;

    /**
     * @param  array<int,string>  $lines
     */
    public function send(string $subject, array $lines): bool
    {
        $to = $this->recipient();

        // Logged regardless: the mail is the alert, the log is the record.
        Log::error('ALERT: '.$subject, ['detail' => $lines]);

        if ($to === null || ! app(MailSettings::class)->isConfirmed()) {
            return false;
        }

        $key = 'alert:'.hash('sha256', $subject);

        if (Cache::has($key)) {
            return false;   // said within the hour
        }

        Cache::put($key, true, self::THROTTLE_SECONDS);

        Mail::to($to)->queue(new SystemAlert($subject, $lines));

        return true;
    }

    /**
     * DOBA_ALERT_EMAIL if set, else the hotel's own contact address —
     * which is whoever reads the enquiries, and therefore whoever reads.
     */
    public function recipient(): ?string
    {
        $configured = trim((string) config('doba.alerts.email', ''));

        if ($configured !== '') {
            return $configured;
        }

        $contact = app(HotelSettings::class)->get('contact.email');

        return is_string($contact) && $contact !== '' ? $contact : null;
    }
}
