<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Gallery;
use App\Models\Media;
use App\Models\RoomType;
use App\Support\Media\DerivativeGenerator;
use GdImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Demo photography, generated rather than shipped.
 *
 * A public repository cannot carry real hotel photographs — they are
 * someone's licensed work — but a hotel site with no images demonstrates
 * nothing and hides every image bug. So these are synthetic: soft
 * gradients with a horizon and a few shapes, sized like real photos, run
 * through the same DerivativeGenerator as a genuine upload. They exist to
 * prove the pipeline (srcset, dimensions, cover selection, LCP) end to
 * end, not to look like the Alps.
 */
class DemoPhotoSeeder extends Seeder
{
    /**
     * Palettes chosen to read as "hotel photography" at a glance: dusk over
     * water, morning light, warm interior, forest, stone, evening.
     *
     * @var array<int,array{0:array{int,int,int},1:array{int,int,int}}>
     */
    protected const PALETTES = [
        [[86, 122, 160], [206, 224, 236]],   // lake at dusk
        [[224, 206, 178], [246, 240, 228]],  // morning light
        [[142, 108, 84], [232, 214, 190]],   // warm interior
        [[74, 104, 86], [198, 216, 196]],    // forest
        [[122, 122, 130], [226, 226, 230]],  // stone
        [[92, 78, 110], [214, 202, 224]],    // evening
    ];

    public function run(): void
    {
        $generator = app(DerivativeGenerator::class);

        $gallery = Gallery::query()->firstOrCreate(['code' => Gallery::HOTEL]);

        foreach ([
            'en' => 'The house', 'de' => 'Das Haus',
            'fr' => 'La maison', 'nl' => 'Het huis',
        ] as $locale => $name) {
            $gallery->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
        }

        $house = [
            ['lake-at-dusk', 0, ['en' => 'The hotel terrace above the lake at dusk', 'de' => 'Die Hotelterrasse über dem See in der Abenddämmerung']],
            ['breakfast-room', 1, ['en' => 'The breakfast room in morning light', 'de' => 'Der Frühstücksraum im Morgenlicht']],
            ['lounge', 2, ['en' => 'The lounge with its open fireplace', 'de' => 'Die Lounge mit offenem Kamin']],
            ['garden', 3, ['en' => 'The garden path down to the water', 'de' => 'Der Gartenweg hinunter zum Wasser']],
            ['spa', 4, ['en' => 'The spa and its stone plunge pool', 'de' => 'Das Spa mit steinernem Tauchbecken']],
            ['terrace-evening', 5, ['en' => 'The terrace on a summer evening', 'de' => 'Die Terrasse an einem Sommerabend']],
        ];

        foreach ($house as $index => [$slug, $palette, $alt]) {
            $this->attach($gallery, "galleries/{$gallery->id}/{$slug}.jpg", $palette, $alt, $index, $generator);
        }

        $rooms = [
            'DBL' => [['double-room', 0, ['en' => 'The double room with its lake-facing balcony', 'de' => 'Das Doppelzimmer mit Balkon zum See']], ['double-bath', 2, ['en' => 'The bathroom of the double room', 'de' => 'Das Bad des Doppelzimmers']]],
            'JSUITE' => [['junior-suite', 2, ['en' => 'The junior suite seating area', 'de' => 'Der Sitzbereich der Junior-Suite']], ['junior-view', 3, ['en' => 'The mountain view from the junior suite', 'de' => 'Der Bergblick aus der Junior-Suite']]],
            'SGL' => [['single-room', 4, ['en' => 'The single room on the quiet side', 'de' => 'Das Einzelzimmer auf der ruhigen Seite']]],
        ];

        foreach ($rooms as $code => $photos) {
            $roomType = RoomType::query()->where('code', $code)->first();

            if ($roomType === null) {
                continue;
            }

            foreach ($photos as $index => [$slug, $palette, $alt]) {
                $this->attach($roomType, "room-types/{$roomType->id}/{$slug}.jpg", $palette, $alt, $index, $generator);
            }
        }
    }

    /**
     * @param  array<string,string>  $alt
     */
    protected function attach(
        object $subject,
        string $path,
        int $palette,
        array $alt,
        int $index,
        DerivativeGenerator $generator,
    ): void {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $disk->put($path, $this->render(1600, 1067, $palette, $index));
        }

        /** @var Media $media */
        $media = $subject->media()->updateOrCreate(['path' => $path], [
            'disk' => 'public',
            'alt' => $alt,
            'sort_order' => $index,
            'is_cover' => $index === 0,
        ]);

        $generator->generate($media);
    }

    /**
     * A stylised alpine landscape: graded sky, low sun, layered ridges
     * receding into haze, and a lake reflecting them.
     *
     * Deliberately illustrative rather than photo-imitating — a placeholder
     * that is obviously a placeholder is honest, while an abstract blur is
     * merely useless. Every element is seeded so a six-photo gallery reads
     * as six different views rather than one image six times.
     */
    protected function render(int $width, int $height, int $palette, int $seed): string
    {
        [$top, $bottom] = self::PALETTES[$palette % count(self::PALETTES)];

        $image = imagecreatetruecolor($width, $height);
        imageantialias($image, true);

        $horizon = (int) round($height * (0.60 + 0.05 * sin($seed * 1.3)));

        // Sky: smoothstep so the light pools near the horizon the way it
        // does at dusk, instead of ramping linearly like a CSS gradient.
        for ($y = 0; $y < $horizon; $y++) {
            $t = $y / max(1, $horizon - 1);
            $e = $t * $t * (3 - 2 * $t);

            imageline($image, 0, $y, $width, $y, imagecolorallocate(
                $image,
                (int) round($top[0] + ($bottom[0] - $top[0]) * $e),
                (int) round($top[1] + ($bottom[1] - $top[1]) * $e),
                (int) round($top[2] + ($bottom[2] - $top[2]) * $e),
            ));
        }

        // Low sun, warm against every palette.
        $sunX = (int) round($width * (0.22 + 0.55 * (($seed * 7) % 10) / 9));
        $sunY = (int) round($horizon * 0.68);
        $sunR = (int) round($width * 0.055);

        for ($r = $sunR * 4; $r > 0; $r -= 4) {
            $alpha = (int) max(0, min(127, 118 - 96 * (1 - $r / ($sunR * 4))));
            imagefilledellipse($image, $sunX, $sunY, $r * 2, $r * 2,
                imagecolorallocatealpha($image, 255, 238, 206, $alpha));
        }

        // Three ridges, each paler and flatter than the one behind it.
        for ($layer = 0; $layer < 3; $layer++) {
            $depth = (1 + $layer) / 4;
            $base = $horizon - (int) round($height * (0.16 - 0.05 * $layer));
            $amplitude = $height * (0.13 - 0.03 * $layer);

            $points = [];

            for ($x = 0; $x <= $width; $x += 16) {
                $u = $x / $width;
                $ridge = sin($u * (3.1 + $layer + $seed * 0.4) + $seed + $layer * 2.2)
                    + 0.45 * sin($u * (7.3 + $layer * 2) + $seed * 1.7);

                $points[] = $x;
                $points[] = (int) round($base - $amplitude * $ridge);
            }

            array_push($points, $width, $height, 0, $height);

            imagefilledpolygon($image, $points, imagecolorallocate(
                $image,
                (int) round($top[0] * (1 - $depth) + 250 * $depth * 0.55),
                (int) round($top[1] * (1 - $depth) + 250 * $depth * 0.55),
                (int) round($top[2] * (1 - $depth) + 250 * $depth * 0.60),
            ));
        }

        // Water: the sky's lower half, darkened, with horizontal glare.
        imagefilledrectangle($image, 0, $horizon, $width, $height,
            imagecolorallocate($image,
                (int) round($top[0] * 0.72 + $bottom[0] * 0.28),
                (int) round($top[1] * 0.72 + $bottom[1] * 0.28),
                (int) round($top[2] * 0.74 + $bottom[2] * 0.26),
            ));

        for ($y = $horizon; $y < $height; $y++) {
            $fade = ($y - $horizon) / max(1, $height - $horizon);

            if ((int) round(sin($y * 0.55 + $seed) * 2) !== 0) {
                continue;
            }

            imageline($image, 0, $y, $width, $y,
                imagecolorallocatealpha($image, 255, 250, 240, (int) round(96 + 30 * $fade)));
        }

        // The sun's reflected column.
        imagefilledrectangle($image, $sunX - $sunR, $horizon, $sunX + $sunR, $height,
            imagecolorallocatealpha($image, 255, 240, 212, 112));

        imagefilter($image, IMG_FILTER_SMOOTH, 6);

        // Edge vignette: concentric 1px frames, each nearly transparent, so
        // the corners fall off the way a lens does.
        for ($i = 0; $i < 26; $i++) {
            imagerectangle($image, $i, $i, $width - 1 - $i, $height - 1 - $i,
                imagecolorallocatealpha($image, 20, 24, 34, 118));
        }

        return $this->encode($image);
    }

    protected function encode(GdImage $image): string
    {
        ob_start();

        try {
            imagejpeg($image, null, 86);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }
}
