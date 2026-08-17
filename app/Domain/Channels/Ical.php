<?php

declare(strict_types=1);

namespace App\Domain\Channels;

use Carbon\CarbonImmutable;

/**
 * The smallest RFC 5545 reader and writer that OTA calendars actually
 * need (§9) — no dependency, because what Booking.com, Airbnb and Vrbo
 * publish is a handful of VEVENTs with UID, DTSTART and DTEND.
 *
 * The one subtlety worth knowing: for `VALUE=DATE` events DTEND is
 * EXCLUSIVE, which is exactly our check_out. A stay of 15–18 September is
 * DTSTART:20260915 / DTEND:20260918, and the night of the 18th is free.
 * Reading DTEND as the last night is the classic off-by-one here, and it
 * blocks a sellable night on every imported booking.
 */
final class Ical
{
    /**
     * Parse a calendar into events.
     *
     * Returns null — rather than an empty array — when the payload is not
     * a complete calendar. The removal guard depends on being able to tell
     * "this feed has no bookings" from "this response was truncated"; an
     * empty array for both is how a half-downloaded feed releases every
     * room the hotel had sold.
     *
     * @return array<int,IcalEvent>|null
     */
    public static function parse(string $payload): ?array
    {
        $payload = str_replace("\r\n", "\n", trim($payload));

        if (! str_contains($payload, 'BEGIN:VCALENDAR') || ! str_contains($payload, 'END:VCALENDAR')) {
            return null;
        }

        // Unfold: a continuation line begins with a space or tab and
        // belongs to the property above it.
        $payload = (string) preg_replace('/\n[ \t]/', '', $payload);

        $events = [];
        $current = null;

        foreach (explode("\n", $payload) as $line) {
            $line = rtrim($line);

            if ($line === 'BEGIN:VEVENT') {
                $current = [];

                continue;
            }

            if ($line === 'END:VEVENT') {
                if ($current !== null) {
                    $event = self::event($current);

                    if ($event !== null) {
                        $events[] = $event;
                    }
                }

                $current = null;

                continue;
            }

            if ($current === null || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            // Strip parameters: "DTSTART;VALUE=DATE" is still DTSTART.
            $current[strtoupper(explode(';', $name)[0])] = self::unescape($value);
        }

        return $events;
    }

    /**
     * @param  array<string,string>  $properties
     */
    private static function event(array $properties): ?IcalEvent
    {
        $start = self::date($properties['DTSTART'] ?? null);
        $end = self::date($properties['DTEND'] ?? null);

        if ($start === null) {
            return null;
        }

        // A VEVENT may omit DTEND, which RFC 5545 defines as a one-day
        // event for DATE values — a single blocked night, not a zero-night
        // stay that would silently release the room.
        $end ??= $start->addDay();

        if ($end->lte($start)) {
            return null;
        }

        return new IcalEvent(
            uid: $properties['UID'] ?? sprintf('%s-%s', $start->toDateString(), $end->toDateString()),
            start: $start,
            end: $end,
            summary: $properties['SUMMARY'] ?? null,
        );
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($value), $m)) {
            return null;
        }

        // Date only, deliberately: a UTC DTSTART of 20260915T220000Z is
        // still the 15th at the property, and an OTA block is a set of
        // nights, never an instant.
        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0);
    }

    private static function unescape(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], trim($value));
    }

    /**
     * Write a calendar of blocked date ranges.
     *
     * Never carries guest names, emails or prices: this URL is handed to
     * third parties and, being a subscription, is fetched by whoever ends
     * up with it. The OTA needs to know the room is gone, nothing more.
     *
     * @param  array<int,IcalEvent>  $events
     */
    public static function write(string $calendarName, array $events, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now('UTC');
        $stamp = $now->setTimezone('UTC')->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Doba//Availability//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($calendarName),
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.self::escape($event->uid);
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->end->format('Ymd');
            $lines[] = 'SUMMARY:'.self::escape($event->summary ?? 'Blocked');
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF, as the spec requires — some readers are strict about it.
        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * Fold to 75 octets, the one formatting rule strict readers enforce.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = substr($line, 0, 75);
        $rest = substr($line, 75);

        foreach (str_split($rest, 74) as $chunk) {
            $folded .= "\r\n ".$chunk;
        }

        return $folded;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $value);
    }
}
