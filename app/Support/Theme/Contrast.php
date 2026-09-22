<?php

declare(strict_types=1);

namespace App\Support\Theme;

/**
 * WCAG contrast, and colours nudged until they have it (§3).
 *
 * A hotelier picks a brand colour for the look; the eyebrow text, the
 * calendar prices and the button labels then have to be READABLE in it,
 * which a gold or a pastel rarely is at 4.5:1. Rather than refusing the
 * colour, the theme derives text-safe variants: the same hue, darkened
 * (or lightened, on a dark ground) only as far as the threshold needs.
 */
final class Contrast
{
    public const AA_TEXT = 4.5;

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * $colour, moved toward black (or toward white when the ground is
     * dark) in small steps until it reads on $ground at $min.
     */
    public static function ensure(string $colour, string $ground, float $min = self::AA_TEXT): string
    {
        $colour = self::normalise($colour);
        $ground = self::normalise($ground);

        if ($colour === null || $ground === null) {
            return $colour ?? '#000000';
        }

        $towards = self::luminance($ground) > 0.4 ? '#000000' : '#ffffff';
        $current = $colour;

        for ($i = 0; $i < 40 && self::ratio($current, $ground) < $min; $i++) {
            $current = self::mix($current, $towards, 0.06);
        }

        return $current;
    }

    /** Linear-ish RGB mix: $amount of $with into $colour. */
    public static function mix(string $colour, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($colour);
        [$r2, $g2, $b2] = self::rgb($with);

        return sprintf('#%02x%02x%02x',
            (int) round($r1 + ($r2 - $r1) * $amount),
            (int) round($g1 + ($g2 - $g1) * $amount),
            (int) round($b1 + ($b2 - $b1) * $amount),
        );
    }

    public static function normalise(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? '#'.strtolower($hex) : null;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function rgb(string $hex): array
    {
        $hex = (string) self::normalise($hex);

        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function luminance(string $hex): float
    {
        $channel = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }
}
