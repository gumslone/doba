<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use App\Models\RoomType;
use App\Models\Setting;
use App\Support\Hotel\HotelSettings;
use Illuminate\Database\Seeder;

/**
 * A demo hotel: enough real content in four languages that the SEO layer
 * has something to render, the sitemap has something to list, and the
 * hreflang set has a genuinely partial translation to expose.
 *
 * "Hotel Alpenhof" is fictional; the address is deliberately not a real
 * property's.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->settings();
        $this->roomTypes();
        $this->pages();

        HotelSettings::flush();
    }

    protected function settings(): void
    {
        Setting::put('general', 'name', 'Hotel Alpenhof');
        Setting::put('general', 'tagline', [
            'en' => 'A family-run hotel above the lake, twenty minutes from the old town.',
            'de' => 'Ein familiengeführtes Hotel über dem See, zwanzig Minuten von der Altstadt.',
            'fr' => 'Un hôtel familial au-dessus du lac, à vingt minutes de la vieille ville.',
            'nl' => 'Een familiehotel boven het meer, twintig minuten van de oude stad.',
        ], translatable: true);

        Setting::put('seo', 'title', [
            'en' => 'Hotel Alpenhof — book direct, best rate guaranteed',
            'de' => 'Hotel Alpenhof — direkt buchen, Bestpreisgarantie',
            'fr' => 'Hotel Alpenhof — réservez en direct, meilleur tarif garanti',
            'nl' => 'Hotel Alpenhof — direct boeken, beste prijs gegarandeerd',
        ], translatable: true);

        Setting::put('seo', 'description', [
            'en' => 'Family-run hotel above the lake with mountain-view rooms, spa and breakfast included. Book direct for the best available rate — no booking fees.',
            'de' => 'Familiengeführtes Hotel über dem See mit Bergblickzimmern, Spa und Frühstück. Direkt buchen zum besten verfügbaren Preis — ohne Buchungsgebühren.',
            'fr' => 'Hôtel familial au-dessus du lac : chambres vue montagne, spa et petit-déjeuner inclus. Réservez en direct au meilleur tarif — sans frais de réservation.',
            'nl' => 'Familiehotel boven het meer met kamers met bergzicht, spa en ontbijt inbegrepen. Boek direct voor de beste prijs — geen boekingskosten.',
        ], translatable: true);

        Setting::put('contact', 'street', 'Seestraße 14');
        Setting::put('contact', 'postal_code', '83700');
        Setting::put('contact', 'city', 'Rottach-Egern');
        Setting::put('contact', 'country', 'DE');
        Setting::put('contact', 'phone', '+49 8022 000000');
        Setting::put('contact', 'email', 'booking@alpenhof.example');
        Setting::put('contact', 'latitude', '47.6903');
        Setting::put('contact', 'longitude', '11.7639');

        Setting::put('branding', 'color_primary', '#1f2937');

        Setting::put('amenities', 'list', ['Free WiFi', 'Spa', 'Parking', 'Breakfast', 'Pets allowed']);
    }

    protected function roomTypes(): void
    {
        $rooms = [
            [
                'code' => 'DBL',
                'base_occupancy' => 2,
                'max_occupancy' => 3,
                'max_adults' => 2,
                'max_children' => 1,
                'size_sqm' => 24,
                'bed_setup' => 'Queen bed',
                'default_rate' => 12500,   // €125.00 — minor units (§5)
                'total_units' => 8,
                'sort_order' => 1,
                'translations' => [
                    'en' => ['slug' => 'double-room', 'name' => 'Double room', 'short' => 'A 24 m² double with a balcony facing the lake.'],
                    'de' => ['slug' => 'doppelzimmer', 'name' => 'Doppelzimmer', 'short' => 'Ein 24 m² großes Doppelzimmer mit Balkon zum See.'],
                    'fr' => ['slug' => 'chambre-double', 'name' => 'Chambre double', 'short' => 'Une chambre double de 24 m² avec balcon face au lac.'],
                    'nl' => ['slug' => 'tweepersoonskamer', 'name' => 'Tweepersoonskamer', 'short' => 'Een tweepersoonskamer van 24 m² met balkon aan het meer.'],
                ],
            ],
            [
                'code' => 'JSUITE',
                'base_occupancy' => 2,
                'max_occupancy' => 4,
                'max_adults' => 3,
                'max_children' => 2,
                'size_sqm' => 38,
                'bed_setup' => 'King bed + sofa bed',
                'default_rate' => 19000,
                'total_units' => 3,
                'sort_order' => 2,
                'translations' => [
                    'en' => ['slug' => 'junior-suite', 'name' => 'Junior suite', 'short' => 'A 38 m² suite with a separate seating area and mountain view.'],
                    'de' => ['slug' => 'junior-suite', 'name' => 'Junior-Suite', 'short' => 'Eine 38 m² große Suite mit separatem Sitzbereich und Bergblick.'],
                    'fr' => ['slug' => 'junior-suite', 'name' => 'Junior suite', 'short' => 'Une suite de 38 m² avec coin salon séparé et vue sur la montagne.'],
                    // Deliberately not translated into Dutch: the hreflang set,
                    // the language switcher and the sitemap must all show three
                    // languages here and four elsewhere.
                ],
            ],
            [
                'code' => 'SGL',
                'base_occupancy' => 1,
                'max_occupancy' => 1,
                'max_adults' => 1,
                'max_children' => 0,
                'size_sqm' => 16,
                'bed_setup' => 'Single bed',
                'default_rate' => 8500,
                'total_units' => 4,
                'sort_order' => 3,
                'translations' => [
                    'en' => ['slug' => 'single-room', 'name' => 'Single room', 'short' => 'A compact 16 m² single, quiet side of the house.'],
                    'de' => ['slug' => 'einzelzimmer', 'name' => 'Einzelzimmer', 'short' => 'Ein kompaktes 16 m² Einzelzimmer auf der ruhigen Seite.'],
                    'fr' => ['slug' => 'chambre-simple', 'name' => 'Chambre simple', 'short' => 'Une chambre simple compacte de 16 m², côté calme.'],
                    'nl' => ['slug' => 'eenpersoonskamer', 'name' => 'Eenpersoonskamer', 'short' => 'Een compacte eenpersoonskamer van 16 m² aan de rustige kant.'],
                ],
            ],
        ];

        foreach ($rooms as $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $roomType = RoomType::updateOrCreate(['code' => $data['code']], $data);

            foreach ($translations as $locale => $translation) {
                $roomType->translations()->updateOrCreate(['locale' => $locale], [
                    'slug' => $translation['slug'],
                    'name' => $translation['name'],
                    'short_description' => $translation['short'],
                    'description' => '<p>'.$translation['short'].'</p>',
                ]);
            }
        }
    }

    protected function pages(): void
    {
        $pages = [
            [
                'code' => 'contact',
                'sort_order' => 1,
                'translations' => [
                    'en' => ['slug' => 'contact', 'title' => 'Contact & directions'],
                    'de' => ['slug' => 'kontakt', 'title' => 'Kontakt & Anfahrt'],
                    'fr' => ['slug' => 'contact', 'title' => 'Contact & accès'],
                    'nl' => ['slug' => 'contact', 'title' => 'Contact & route'],
                ],
            ],
            [
                'code' => 'privacy',
                'sort_order' => 2,
                'translations' => [
                    'en' => ['slug' => 'privacy', 'title' => 'Privacy policy'],
                    'de' => ['slug' => 'datenschutz', 'title' => 'Datenschutzerklärung'],
                    'fr' => ['slug' => 'confidentialite', 'title' => 'Politique de confidentialité'],
                    'nl' => ['slug' => 'privacy', 'title' => 'Privacybeleid'],
                ],
            ],
            [
                'code' => 'imprint',
                'sort_order' => 3,
                'translations' => [
                    'en' => ['slug' => 'imprint', 'title' => 'Imprint'],
                    'de' => ['slug' => 'impressum', 'title' => 'Impressum'],
                    'fr' => ['slug' => 'mentions-legales', 'title' => 'Mentions légales'],
                    'nl' => ['slug' => 'colofon', 'title' => 'Colofon'],
                ],
            ],
        ];

        foreach ($pages as $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $page = Page::updateOrCreate(['code' => $data['code']], $data + [
                'template' => 'default',
                'is_published' => true,
                'show_in_menu' => true,
            ]);

            foreach ($translations as $locale => $translation) {
                $page->translations()->updateOrCreate(['locale' => $locale], [
                    'slug' => $translation['slug'],
                    'title' => $translation['title'],
                    'body' => '<p>'.$translation['title'].'</p>',
                ]);
            }
        }
    }
}
