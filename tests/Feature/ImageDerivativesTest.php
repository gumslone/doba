<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\RoomType;
use App\Support\Media\DerivativeGenerator;
use App\Support\Media\ResponsiveImage;
use Illuminate\Support\Facades\Storage;

function makeTestImage(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 180, 160));

    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

function makeMedia(string $path, string $contents): Media
{
    Storage::disk('public')->put($path, $contents);

    $roomType = RoomType::create([
        'code' => 'IMG-'.uniqid(),
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'total_units' => 1,
    ]);

    return $roomType->media()->create(['path' => $path, 'disk' => 'public']);
}

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('doba.seo.image_widths', [480, 768, 1024]);
});

it('writes a WebP derivative for each configured width up to the source width', function (): void {
    $media = makeMedia('rooms/hero.png', makeTestImage(1000, 750));

    $written = app(DerivativeGenerator::class)->generate($media);

    expect($written)->toBe(2);

    Storage::disk('public')->assertExists('rooms/hero-480.webp');
    Storage::disk('public')->assertExists('rooms/hero-768.webp');
    // Never upscaled: the 1024 candidate would ship bytes for no pixels.
    Storage::disk('public')->assertMissing('rooms/hero-1024.webp');

    // The derivative must actually be WebP at the requested width, not a
    // renamed copy of the original.
    $info = getimagesizefromstring((string) Storage::disk('public')->get('rooms/hero-480.webp'));

    expect($info[0])->toBe(480)
        ->and($info['mime'])->toBe('image/webp');
});

it('backfills intrinsic dimensions onto the media row', function (): void {
    $media = makeMedia('rooms/hero.png', makeTestImage(1000, 750));

    expect($media->width)->toBeNull();

    app(DerivativeGenerator::class)->generate($media);

    expect($media->refresh())
        ->width->toBe(1000)
        ->height->toBe(750);
});

it('skips existing derivatives unless forced', function (): void {
    $media = makeMedia('rooms/hero.png', makeTestImage(1000, 750));
    $generator = app(DerivativeGenerator::class);

    expect($generator->generate($media))->toBe(2)
        ->and($generator->generate($media))->toBe(0)
        ->and($generator->generate($media, force: true))->toBe(2);
});

it('ignores remote URLs and missing files without erroring', function (): void {
    $roomType = RoomType::create([
        'code' => 'REMOTE', 'base_occupancy' => 2, 'max_occupancy' => 2, 'total_units' => 1,
    ]);

    $remote = $roomType->media()->create(['path' => 'https://cdn.example/x.jpg', 'disk' => 'public']);
    $missing = $roomType->media()->create(['path' => 'rooms/deleted.jpg', 'disk' => 'public']);

    $generator = app(DerivativeGenerator::class);

    expect($generator->generate($remote))->toBe(0)
        ->and($generator->generate($missing))->toBe(0);
});

it('feeds the generated derivatives straight into the srcset', function (): void {
    $media = makeMedia('rooms/hero.png', makeTestImage(1000, 750));

    // Before generation the srcset is empty — the <img> falls back to src
    // rather than advertising files that 404.
    expect(ResponsiveImage::srcset($media))->toBeNull();

    app(DerivativeGenerator::class)->generate($media);

    $srcset = ResponsiveImage::srcset($media->refresh());

    expect($srcset)->toContain('rooms/hero-480.webp 480w')
        ->toContain('rooms/hero-768.webp 768w')
        ->not->toContain('1024w');
});

it('processes every media row from the artisan command', function (): void {
    makeMedia('rooms/a.png', makeTestImage(600, 400));
    makeMedia('rooms/b.png', makeTestImage(600, 400));

    $this->artisan('doba:images')
        ->expectsOutputToContain('Processed 2 media rows, wrote 2 derivatives.')
        ->assertSuccessful();

    Storage::disk('public')->assertExists('rooms/a-480.webp');
    Storage::disk('public')->assertExists('rooms/b-480.webp');
});
