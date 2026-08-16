<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\Concerns\HasMedia;
use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one place an uploaded image becomes a media row: stored under a
 * random name (never the user-supplied filename — that string is attacker
 * input twice over, as a path and as markup), measured for the intrinsic
 * width/height every <img> needs, and pushed through the WebP derivative
 * generator immediately so the first visitor is never the image processor.
 */
class MediaUpload
{
    public function __construct(protected DerivativeGenerator $derivatives) {}

    public function attach(Model&HasMedia $subject, UploadedFile $file): Media
    {
        $directory = Str::plural(Str::kebab(class_basename($subject))).'/'.$subject->getKey();

        $path = $file->storeAs(
            $directory,
            Str::random(20).'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg'),
            'public'
        );

        /** @var Media $media */
        $media = $subject->media()->create([
            'path' => (string) $path,
            'disk' => 'public',
            'sort_order' => ((int) $subject->media()->max('sort_order')) + 1,
            // First photo of a subject becomes its cover automatically —
            // a room with photos but no cover renders as if it had none.
            'is_cover' => ! $subject->media()->where('is_cover', true)->exists(),
        ]);

        $this->derivatives->generate($media);

        return $media;
    }

    /**
     * Remove the row, the original file and every derivative — orphaned
     * files on the uploads disk are what quietly eats a small host.
     */
    public function remove(Media $media): void
    {
        $disk = Storage::disk($media->disk);

        foreach (ResponsiveImage::widths() as $width) {
            $disk->delete(ResponsiveImage::derivativePath($media->path, $width));
        }

        $disk->delete($media->path);

        $wasCover = $media->is_cover;
        $subject = $media->mediable;

        $media->delete();

        // Promote the next photo so the subject never sits coverless.
        if ($wasCover && $subject instanceof HasMedia) {
            $subject->media()->orderBy('sort_order')->first()?->update(['is_cover' => true]);
        }
    }
}
