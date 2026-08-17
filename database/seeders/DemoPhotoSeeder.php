<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Gallery;
use App\Models\Media;
use App\Models\RoomType;
use App\Support\Media\DerivativeGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Demo imagery: illustrated scenes, shipped as SVG.
 *
 * A public repository cannot carry real hotel photographs — they are
 * someone's licensed work — but a hotel site with no images demonstrates
 * nothing and hides every image bug.
 *
 * These are drawn scenes rather than the gradients that used to live
 * here: mountains at dusk, a city skyline, a lake, an interior. Vector,
 * so 26 of them cost 19 KB and stay crisp at any size, and obviously
 * illustrations rather than photographs — a placeholder that is plainly a
 * placeholder is honest, where a blurred rectangle is merely useless.
 *
 * They carry no scripts and no external references, and uploads still
 * refuse SVG (jpg/png/webp only), so nothing here widens what a hotelier
 * can put on their own site.
 */
class DemoPhotoSeeder extends Seeder
{
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
            ['lake-at-dusk', 'lake-at-dusk', ['en' => 'The lake below the hotel at dusk', 'de' => 'Der See unterhalb des Hotels in der Abenddämmerung']],
            ['breakfast-room', 'misty-hills', ['en' => 'The hills behind the house on a misty morning', 'de' => 'Die Hügel hinter dem Haus an einem nebligen Morgen']],
            ['lounge', 'interior-evening', ['en' => 'The lounge in the evening', 'de' => 'Die Lounge am Abend']],
            ['garden', 'forest-cabin', ['en' => 'The forest path behind the hotel', 'de' => 'Der Waldweg hinter dem Hotel']],
            ['spa', 'shore', ['en' => 'The shore a short walk from the house', 'de' => 'Das Ufer, wenige Schritte vom Haus']],
            ['terrace-evening', 'water-at-night', ['en' => 'The water at night from the terrace', 'de' => 'Das Wasser bei Nacht von der Terrasse']],
        ];

        foreach ($house as $index => [$slug, $scene, $alt]) {
            $this->attach($gallery, "galleries/{$gallery->id}/{$slug}.svg", $scene, $alt, $index, $generator);
        }

        $rooms = [
            'DBL' => [
                ['double-room', 'room-interior', ['en' => 'The double room', 'de' => 'Das Doppelzimmer']],
                ['double-view', 'lake-at-dusk', ['en' => 'The view from the double room', 'de' => 'Der Blick aus dem Doppelzimmer']],
            ],
            'JSUITE' => [
                ['junior-suite', 'interior-evening', ['en' => 'The junior suite in the evening', 'de' => 'Die Junior-Suite am Abend']],
                ['junior-view', 'misty-hills', ['en' => 'The view from the junior suite', 'de' => 'Der Blick aus der Junior-Suite']],
            ],
            'SGL' => [['single-room', 'room-interior', ['en' => 'The single room on the quiet side', 'de' => 'Das Einzelzimmer auf der ruhigen Seite']]],
        ];

        foreach ($rooms as $code => $photos) {
            $roomType = RoomType::query()->where('code', $code)->first();

            if ($roomType === null) {
                continue;
            }

            foreach ($photos as $index => [$slug, $scene, $alt]) {
                $this->attach($roomType, "room-types/{$roomType->id}/{$slug}.svg", $scene, $alt, $index, $generator);
            }
        }
    }

    /**
     * @param  array<string,string>  $alt
     */
    protected function attach(
        object $subject,
        string $path,
        string $sceneName,
        array $alt,
        int $index,
        DerivativeGenerator $generator,
    ): void {
        $disk = Storage::disk('public');
        $scene = $this->scene($sceneName);

        if (! $disk->exists($path)) {
            $disk->put($path, $scene['svg']);
        }

        /** @var Media $media */
        $media = $subject->media()->updateOrCreate(['path' => $path], [
            'disk' => 'public',
            'alt' => $alt,
            'sort_order' => $index,
            'is_cover' => $index === 0,
            // Taken from the viewBox. Every <img> needs them to reserve its
            // box — the CLS half of the Core Web Vitals budget (§11) —
            // and an SVG has no raster header to read them out of.
            'width' => $scene['width'],
            'height' => $scene['height'],
        ]);

        $generator->generate($media);
    }

    /**
     * Load the named scene and read its intrinsic size.
     *
     * Each image names the scene it wants rather than taking whatever the
     * next file happens to be. The alt text already tells a screen reader
     * what the picture is — "the garden path down to the water" — so the
     * picture has to actually be that, or the alt text is a lie told to
     * the people who most depend on it.
     *
     * @return array{svg:string,width:int,height:int}
     */
    protected function scene(string $scene): array
    {
        $file = __DIR__.'/scenes/'.$scene.'.svg';

        if (! is_file($file)) {
            throw new RuntimeException("No demo scene [{$scene}].");
        }

        $svg = (string) file_get_contents($file);

        preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $box);

        return [
            'svg' => $svg,
            'width' => (int) round((float) ($box[1] ?? 1440)),
            'height' => (int) round((float) ($box[2] ?? 900)),
        ];
    }
}
