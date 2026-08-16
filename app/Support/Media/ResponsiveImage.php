<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * srcset / WebP generation for the public site.
 *
 * The Core Web Vitals budget in §11 is mostly won or lost on images: a hotel
 * site is photographs, and a 3 MB hero shipped at full resolution to a phone
 * is the LCP failure that costs the ranking the whole site exists for.
 *
 * Derivatives are named <path>-<width>.webp next to the original and are
 * generated at upload time, not on request — a hotelier uploading forty spa
 * photos must not turn the first visitor into the image processor. This
 * class only *addresses* them, and falls back to the original when a
 * derivative is missing so a half-processed upload still renders.
 */
final class ResponsiveImage
{
    /**
     * @return array<int,int>
     */
    public static function widths(): array
    {
        /** @var array<int,int> $widths */
        $widths = config('doba.seo.image_widths', [480, 768, 1024, 1440, 1920]);

        sort($widths);

        return $widths;
    }

    /**
     * The srcset attribute for a media row, capped at the image's own
     * intrinsic width — upscaling a 900px photo to a 1920px candidate ships
     * bytes for no pixels and can pick the blurrier source on a 2× screen.
     */
    public static function srcset(Media $media): ?string
    {
        $disk = Storage::disk($media->disk);
        $candidates = [];

        foreach (self::widths() as $width) {
            if ($media->width !== null && $width > $media->width) {
                continue;
            }

            $derivative = self::derivativePath($media->path, $width);

            if ($disk->exists($derivative)) {
                $candidates[] = $disk->url($derivative).' '.$width.'w';
            }
        }

        return $candidates === [] ? null : implode(', ', $candidates);
    }

    /**
     * Default `sizes`. Overridden per call site — the value has to describe
     * the layout, and a wrong `sizes` makes a correct `srcset` useless
     * because the browser picks its candidate before any CSS is applied.
     */
    public static function sizes(?string $sizes = null): string
    {
        return $sizes ?? '(max-width: 768px) 100vw, (max-width: 1280px) 50vw, 640px';
    }

    public static function derivativePath(string $path, int $width): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = $extension !== ''
            ? substr($path, 0, -(strlen($extension) + 1))
            : $path;

        return "{$base}-{$width}.webp";
    }

    /**
     * Everything an <img> needs, in one array, so the Blade component stays
     * dumb and the logic stays testable.
     *
     * @return array<string,mixed>
     */
    public static function attributes(Media $media, ?string $sizes = null, bool $eager = false): array
    {
        return array_filter([
            'src' => $media->url(),
            'srcset' => self::srcset($media),
            'sizes' => self::srcset($media) ? self::sizes($sizes) : null,
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->altFor(),
            // The LCP image must not be lazy — lazy-loading the hero is a
            // measurable, self-inflicted LCP regression.
            'loading' => $eager ? 'eager' : 'lazy',
            'decoding' => 'async',
            'fetchpriority' => $eager ? 'high' : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
