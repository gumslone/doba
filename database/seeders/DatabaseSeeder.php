<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Event;
use App\Models\Faq;
use App\Models\Page;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use App\Models\Setting;
use App\Models\User;
use App\Support\Hotel\HotelSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

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
        $this->adminUser();
        $this->settings();
        $this->roomTypes();
        $this->amenities();
        $this->pages();
        $this->faqs();
        $this->events();
        $this->seasons();

        // Fill the calendar through the bookable window so the demo does
        // not open on the "empty calendar looks broken" state (§16 step 7).
        Artisan::call('availability:extend');

        // Generated demo photography — a hotel site with no images
        // demonstrates nothing and hides every image bug.
        $this->call(DemoPhotoSeeder::class);

        HotelSettings::flush();
    }

    protected function adminUser(): void
    {
        // /admin login for the demo install. Override via env before
        // seeding anything reachable from the internet; the wizard (§16)
        // replaces this with a proper owner-account step.
        User::query()->firstOrCreate(
            ['email' => (string) config('doba.admin.email')],
            [
                'name' => 'Admin',
                'password' => Hash::make((string) config('doba.admin.password')),
            ]
        );
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

    protected function events(): void
    {
        $events = [
            [
                'starts_at' => now()->addDays(12)->setTime(18, 0),
                'ends_at' => now()->addDays(12)->setTime(21, 0),
                'location' => null, // at the hotel
                'translations' => [
                    'en' => ['slug' => 'wine-tasting-evening', 'title' => 'Wine tasting evening', 'excerpt' => 'Six regional wines with a guided tasting by our sommelier.'],
                    'de' => ['slug' => 'weinverkostung', 'title' => 'Weinverkostung am Abend', 'excerpt' => 'Sechs regionale Weine, begleitet von unserer Sommelière.'],
                    'fr' => ['slug' => 'soiree-degustation', 'title' => 'Soirée dégustation de vins', 'excerpt' => 'Six vins régionaux commentés par notre sommelière.'],
                    'nl' => ['slug' => 'wijnproeverij', 'title' => 'Wijnproeverij', 'excerpt' => 'Zes regionale wijnen met toelichting van onze sommelier.'],
                ],
            ],
            [
                'starts_at' => now()->addDays(26)->setTime(19, 30),
                'ends_at' => null,
                'location' => 'Lakeside terrace',
                'translations' => [
                    'en' => ['slug' => 'jazz-on-the-terrace', 'title' => 'Jazz on the terrace', 'excerpt' => 'An open-air trio set at sunset — free for hotel guests.'],
                    'de' => ['slug' => 'jazz-auf-der-terrasse', 'title' => 'Jazz auf der Terrasse', 'excerpt' => 'Open-Air-Trio bei Sonnenuntergang — für Hausgäste kostenlos.'],
                    'fr' => ['slug' => 'jazz-en-terrasse', 'title' => 'Jazz en terrasse', 'excerpt' => 'Un trio en plein air au coucher du soleil — gratuit pour nos hôtes.'],
                    // Deliberately untranslated into Dutch, like the junior
                    // suite: the partial-translation path stays exercised.
                ],
            ],
        ];

        foreach ($events as $index => $data) {
            $translations = $data['translations'];
            unset($data['translations']);

            $firstSlug = reset($translations)['slug'];

            $event = Event::query()
                ->whereHas('translations', static fn ($q) => $q->where('slug', $firstSlug))
                ->first() ?? Event::create($data + ['is_published' => true]);

            $event->update($data + ['is_published' => true]);

            foreach ($translations as $locale => $translation) {
                $event->translations()->updateOrCreate(['locale' => $locale], [
                    'slug' => $translation['slug'],
                    'title' => $translation['title'],
                    'excerpt' => $translation['excerpt'],
                    'body' => '<p>'.$translation['excerpt'].'</p>',
                ]);
            }
        }
    }

    protected function seasons(): void
    {
        $season = Season::updateOrCreate(['name' => 'High season'], [
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(90)->toDateString(),
            'priority' => 10,
        ]);

        $double = RoomType::query()->where('code', 'DBL')->first();

        if ($double !== null) {
            // Weekend surcharge on the double room: one row instead of
            // twenty-six availability edits (§5).
            SeasonRate::updateOrCreate([
                'season_id' => $season->id,
                'room_type_id' => $double->id,
                'weekday_mask' => SeasonRate::SATURDAY | SeasonRate::SUNDAY,
            ], ['price' => 14500]);
        }
    }

    protected function amenities(): void
    {
        $amenities = [
            ['icon' => 'wifi', 'sort' => 1, 'names' => ['en' => 'Free WiFi', 'de' => 'Kostenloses WLAN', 'fr' => 'Wi-Fi gratuit', 'nl' => 'Gratis wifi'], 'rooms' => ['DBL', 'JSUITE', 'SGL']],
            ['icon' => 'balcony', 'sort' => 2, 'names' => ['en' => 'Balcony', 'de' => 'Balkon', 'fr' => 'Balcon', 'nl' => 'Balkon'], 'rooms' => ['DBL', 'JSUITE']],
            ['icon' => 'minibar', 'sort' => 3, 'names' => ['en' => 'Minibar', 'de' => 'Minibar', 'fr' => 'Minibar', 'nl' => 'Minibar'], 'rooms' => ['JSUITE']],
            ['icon' => 'mountain', 'sort' => 4, 'names' => ['en' => 'Mountain view', 'de' => 'Bergblick', 'fr' => 'Vue montagne', 'nl' => 'Bergzicht'], 'rooms' => ['DBL', 'JSUITE']],
            ['icon' => 'safe', 'sort' => 5, 'names' => ['en' => 'In-room safe', 'de' => 'Zimmersafe', 'fr' => 'Coffre-fort', 'nl' => 'Kluis op de kamer'], 'rooms' => ['DBL', 'JSUITE', 'SGL']],
        ];

        foreach ($amenities as $data) {
            $amenity = Amenity::updateOrCreate(['icon' => $data['icon']], [
                'sort_order' => $data['sort'],
            ]);

            foreach ($data['names'] as $locale => $name) {
                $amenity->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
            }

            $amenity->roomTypes()->sync(
                RoomType::query()->whereIn('code', $data['rooms'])->pluck('id')
            );
        }
    }

    protected function faqs(): void
    {
        $faqs = [
            [
                'sort_order' => 1,
                'translations' => [
                    'en' => ['Is parking available?', 'Yes — free on-site parking for hotel guests, no reservation needed.'],
                    'de' => ['Gibt es Parkplätze?', 'Ja — kostenlose hoteleigene Parkplätze für Gäste, keine Reservierung nötig.'],
                    'fr' => ['Y a-t-il un parking ?', 'Oui — parking gratuit sur place pour les clients de l’hôtel, sans réservation.'],
                    'nl' => ['Is er parkeergelegenheid?', 'Ja — gratis parkeren op eigen terrein voor hotelgasten, reserveren is niet nodig.'],
                ],
            ],
            [
                'sort_order' => 2,
                'translations' => [
                    'en' => ['Are pets welcome?', 'Dogs are welcome in selected rooms for a small nightly fee — please mention your dog when booking.'],
                    'de' => ['Sind Haustiere erlaubt?', 'Hunde sind in ausgewählten Zimmern gegen eine kleine Gebühr pro Nacht willkommen — bitte bei der Buchung angeben.'],
                    'fr' => ['Les animaux sont-ils acceptés ?', 'Les chiens sont les bienvenus dans certaines chambres moyennant un petit supplément par nuit — merci de le préciser à la réservation.'],
                    'nl' => ['Zijn huisdieren welkom?', 'Honden zijn welkom in geselecteerde kamers tegen een kleine toeslag per nacht — vermeld uw hond bij het boeken.'],
                ],
            ],
            [
                'sort_order' => 3,
                'translations' => [
                    'en' => ['Can I check in late?', 'Yes — the front desk is staffed until 22:00, and self check-in is available after that if you let us know in advance.'],
                    'de' => ['Ist ein später Check-in möglich?', 'Ja — die Rezeption ist bis 22:00 Uhr besetzt, danach ist Self-Check-in nach vorheriger Absprache möglich.'],
                    'fr' => ['Puis-je arriver tard ?', 'Oui — la réception est ouverte jusqu’à 22 h ; au-delà, un enregistrement autonome est possible si vous nous prévenez à l’avance.'],
                    'nl' => ['Kan ik laat inchecken?', 'Ja — de receptie is bezet tot 22:00 uur; daarna is zelf inchecken mogelijk als u ons vooraf informeert.'],
                ],
            ],
        ];

        foreach ($faqs as $data) {
            $faq = Faq::updateOrCreate(['sort_order' => $data['sort_order']], [
                'is_published' => true,
            ]);

            foreach ($data['translations'] as $locale => [$question, $answer]) {
                $faq->translations()->updateOrCreate(['locale' => $locale], [
                    'question' => $question,
                    'answer' => $answer,
                ]);
            }
        }
    }

    protected function pages(): void
    {
        // No "contact" CMS page: /kontakt etc. is a dedicated route with the
        // enquiry form, and a CMS page on the same slug would be shadowed.
        $pages = [
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
