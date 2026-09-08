<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Support\Hotel\HotelSettings;

/**
 * The words in the guest mails, as the hotelier wants them (§13).
 *
 * The shipped texts are the fallback in every language. A hotel that
 * writes its own greeting stores it per language as a setting, with the
 * same `:name`-style placeholders the shipped text uses — and a language
 * it never wrote keeps the shipped text, so switching the site's
 * languages never sends a guest an empty line.
 *
 * Only the prose is editable. The reference, the dates, the amounts and
 * the button are facts the mail carries, not wording.
 */
final class Wording
{
    public const GROUP = 'mail_text';

    /**
     * What can be edited, and the placeholders each text may use.
     *
     * @var array<string,array<int,string>>
     */
    public const KEYS = [
        'booking_subject' => ['reference', 'hotel', 'name'],
        'booking_intro' => ['name', 'hotel', 'reference'],
        'pre_arrival_subject' => ['hotel', 'name', 'date'],
        'pre_arrival_heading' => ['name', 'hotel', 'date'],
        'pre_arrival_intro' => ['hotel', 'date', 'name'],
        'pre_arrival_outro' => ['hotel', 'name', 'date'],
        'post_stay_subject' => ['hotel', 'name'],
        'post_stay_heading' => ['name', 'hotel'],
        'post_stay_intro' => ['hotel', 'name'],
        'post_stay_outro' => ['hotel', 'name'],
    ];

    /**
     * The text for a key in a language: the hotel's own if it wrote one,
     * the shipped one otherwise, placeholders filled either way.
     *
     * @param  array<string,mixed>  $replace
     */
    public static function text(string $key, array $replace, string $locale): string
    {
        $custom = self::custom($key, $locale);

        if ($custom !== null) {
            return self::fill($custom, $replace);
        }

        return (string) __('mail.'.$key, $replace, $locale);
    }

    /**
     * What the hotel wrote for a key in a language, or null for "the
     * shipped text, please".
     */
    public static function custom(string $key, string $locale): ?string
    {
        $stored = app(HotelSettings::class)->all()[self::GROUP.'.'.$key] ?? null;

        if (! is_array($stored)) {
            return null;
        }

        $text = $stored[$locale] ?? null;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /**
     * The shipped text with its placeholders still visible — what the
     * editor shows as the starting point.
     */
    public static function shipped(string $key, string $locale): string
    {
        return (string) __('mail.'.$key, [], $locale);
    }

    /**
     * The same `:placeholder` substitution the translator does, longest
     * key first so `:hotel_name` could never be eaten by `:hotel`.
     *
     * @param  array<string,mixed>  $replace
     */
    protected static function fill(string $text, array $replace): string
    {
        uksort($replace, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($replace as $key => $value) {
            $text = str_replace(':'.$key, (string) $value, $text);
        }

        return $text;
    }
}
