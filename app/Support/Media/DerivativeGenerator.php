<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\Media;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes the WebP derivatives that ResponsiveImage addresses.
 *
 * The two classes are deliberately split: this one runs at upload time and
 * from `doba:images`, opens files and burns CPU; ResponsiveImage runs on
 * every page render and only builds URLs. The render path must never fall
 * into the generate path — a hotelier uploading forty spa photos is a queue
 * job's problem, not the first visitor's.
 *
 * GD only (§2 requires it anyway). Reads and writes through the Storage
 * disk rather than local paths, so it works unchanged when uploads move to
 * S3-compatible storage for backups.
 */
final class DerivativeGenerator
{
    public const WEBP_QUALITY = 82;

    /**
     * Generate every missing derivative for one media row and backfill its
     * intrinsic dimensions. Returns the number of files written.
     */
    public function generate(Media $media, bool $force = false): int
    {
        $disk = Storage::disk($media->disk);

        if (str_starts_with($media->path, 'http') || ! $disk->exists($media->path)) {
            return 0;
        }

        // An SVG is already every size. Resizing one to 480px would throw
        // away the only advantage it has, and GD cannot read it anyway —
        // so it is served as it is, and ResponsiveImage emits no srcset,
        // which is the correct markup for a resolution-independent image.
        if (str_ends_with(strtolower($media->path), '.svg')) {
            return 0;
        }

        $source = $this->decode((string) $disk->get($media->path));

        if ($source === null) {
            return 0;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        // Backfill intrinsic dimensions: they are what lets every <img>
        // reserve its box (the CLS half of the Core Web Vitals budget), and
        // rows imported before this class existed do not have them.
        if ($media->width !== $width || $media->height !== $height) {
            $media->forceFill(['width' => $width, 'height' => $height])->save();
        }

        $written = 0;

        foreach (ResponsiveImage::widths() as $targetWidth) {
            // Never upscale — a 900px photo gets no 1440px derivative, and
            // ResponsiveImage caps the srcset at the same boundary.
            if ($targetWidth > $width) {
                continue;
            }

            $path = ResponsiveImage::derivativePath($media->path, $targetWidth);

            if (! $force && $disk->exists($path)) {
                continue;
            }

            $disk->put($path, $this->encode($this->scale($source, $targetWidth)));
            $written++;
        }

        imagedestroy($source);

        return $written;
    }

    protected function decode(string $contents): ?GdImage
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        // Palette PNGs/GIFs must become truecolor before scaling, and alpha
        // must survive both the scale and the WebP encode — a logo whose
        // transparent corners turn black is how a hotelier learns to
        // distrust the whole image pipeline.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    protected function scale(GdImage $source, int $targetWidth): GdImage
    {
        // Default (bilinear) interpolation: visually fine for downscaling
        // photographs, and IMG_BICUBIC returns false outright on some GD
        // builds (macOS/Homebrew PHP 8.5 among them).
        $scaled = imagescale($source, $targetWidth);

        if ($scaled === false) {
            throw new RuntimeException('imagescale() failed.');
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);

        return $scaled;
    }

    protected function encode(GdImage $image): string
    {
        ob_start();

        try {
            if (! imagewebp($image, null, self::WEBP_QUALITY)) {
                throw new RuntimeException('imagewebp() failed.');
            }

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }
}
