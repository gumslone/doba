<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Event;
use App\Models\Extra;
use App\Models\Faq;
use App\Models\Page;
use App\Models\RatePlan;
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
        $this->extras();
        $this->ratePlans();
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

        Setting::put('general', 'star_rating', 4);
        Setting::put('general', 'since', 1954);

        // The four-up strip under the booking bar — what makes this house
        // worth choosing, in the hotelier's own words.
        Setting::put('general', 'usps', [
            ['icon' => 'building', 'title' => '28 rooms', 'subtitle' => 'Never a crowd'],
            ['icon' => 'spa', 'title' => 'Alpine spa', 'subtitle' => '900 m², mountain view'],
            ['icon' => 'dining', 'title' => 'Half board', 'subtitle' => 'Regional, four courses'],
            ['icon' => 'clock', 'title' => '24 h reception', 'subtitle' => 'Still there late'],
        ]);

        Setting::put('policy', 'cancellation', [
            'en' => 'Free cancellation up to 48 hours before arrival on flexible rates.',
            'de' => 'Kostenfreie Stornierung bis 48 Stunden vor Anreise bei flexiblen Raten.',
            'fr' => 'Annulation gratuite jusqu’à 48 heures avant l’arrivée sur les tarifs flexibles.',
            'nl' => 'Gratis annuleren tot 48 uur voor aankomst bij flexibele tarieven.',
        ], translatable: true);

        Setting::put('branding', 'color_primary', '#20362c');
        Setting::put('branding', 'color_accent', '#a8823f');

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
        // What a guest actually wants to know before booking, in the order
        // they ask: the room, then the bathroom, then comfort, then the view.
        $amenities = [
            ['wifi', 'room', 1, ['en' => 'Free WiFi', 'de' => 'Kostenloses WLAN', 'fr' => 'Wi-Fi gratuit', 'nl' => 'Gratis wifi'], ['DBL', 'JSUITE', 'SGL']],
            ['desk', 'room', 2, ['en' => 'Desk & reading chair', 'de' => 'Schreibtisch & Lesesessel', 'fr' => 'Bureau & fauteuil de lecture', 'nl' => 'Bureau & leesstoel'], ['DBL', 'JSUITE', 'SGL']],
            ['tv', 'room', 3, ['en' => 'Smart TV', 'de' => 'Smart-TV', 'fr' => 'Smart TV', 'nl' => 'Smart-tv'], ['DBL', 'JSUITE']],
            ['safe', 'room', 4, ['en' => 'In-room safe', 'de' => 'Zimmersafe', 'fr' => 'Coffre-fort', 'nl' => 'Kluis op de kamer'], ['DBL', 'JSUITE', 'SGL']],
            ['minibar', 'room', 5, ['en' => 'Minibar', 'de' => 'Minibar', 'fr' => 'Minibar', 'nl' => 'Minibar'], ['JSUITE']],

            ['shower', 'bathroom', 10, ['en' => 'Walk-in shower', 'de' => 'Ebenerdige Dusche', 'fr' => 'Douche à l’italienne', 'nl' => 'Inloopdouche'], ['DBL', 'JSUITE', 'SGL']],
            ['bathtub', 'bathroom', 11, ['en' => 'Bathtub', 'de' => 'Badewanne', 'fr' => 'Baignoire', 'nl' => 'Ligbad'], ['JSUITE']],
            ['wc', 'bathroom', 12, ['en' => 'Private WC', 'de' => 'Eigenes WC', 'fr' => 'WC privé', 'nl' => 'Eigen toilet'], ['DBL', 'JSUITE', 'SGL']],
            ['hairdryer', 'bathroom', 13, ['en' => 'Hairdryer', 'de' => 'Haartrockner', 'fr' => 'Sèche-cheveux', 'nl' => 'Föhn'], ['DBL', 'JSUITE', 'SGL']],
            ['toiletries', 'bathroom', 14, ['en' => 'Organic toiletries', 'de' => 'Bio-Pflegeprodukte', 'fr' => 'Produits de toilette bio', 'nl' => 'Biologische toiletartikelen'], ['DBL', 'JSUITE']],

            ['heating', 'comfort', 20, ['en' => 'Underfloor heating', 'de' => 'Fußbodenheizung', 'fr' => 'Chauffage au sol', 'nl' => 'Vloerverwarming'], ['DBL', 'JSUITE']],
            ['coffee', 'comfort', 21, ['en' => 'Coffee & tea making', 'de' => 'Kaffee- & Teezubereitung', 'fr' => 'Nécessaire à café et thé', 'nl' => 'Koffie- en theefaciliteiten'], ['DBL', 'JSUITE', 'SGL']],
            ['soundproof', 'comfort', 22, ['en' => 'Soundproofed windows', 'de' => 'Schallschutzfenster', 'fr' => 'Fenêtres insonorisées', 'nl' => 'Geluidsisolerende ramen'], ['DBL', 'JSUITE', 'SGL']],

            ['balcony', 'view', 30, ['en' => 'Private balcony', 'de' => 'Eigener Balkon', 'fr' => 'Balcon privé', 'nl' => 'Eigen balkon'], ['DBL', 'JSUITE']],
            ['mountain', 'view', 31, ['en' => 'Mountain view', 'de' => 'Bergblick', 'fr' => 'Vue montagne', 'nl' => 'Bergzicht'], ['DBL', 'JSUITE']],
            ['lake', 'view', 32, ['en' => 'Lake view', 'de' => 'Seeblick', 'fr' => 'Vue sur le lac', 'nl' => 'Uitzicht op het meer'], ['JSUITE']],
        ];

        foreach ($amenities as [$icon, $category, $sort, $names, $rooms]) {
            $amenity = Amenity::updateOrCreate(['icon' => $icon], [
                'category' => $category,
                'sort_order' => $sort,
            ]);

            foreach ($names as $locale => $name) {
                $amenity->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
            }

            $amenity->roomTypes()->sync(
                RoomType::query()->whereIn('code', $rooms)->pluck('id')
            );
        }
    }

    protected function ratePlans(): void
    {
        // adjustment_value is basis points for percent plans (-1000 = -10%).
        $plans = [
            ['FLEX', 'standard', 'percent', 0, true, 48, 10, [], [
                'en' => ['Flexible rate', 'Cancel free of charge up to 48 hours before arrival.', 'Free cancellation up to 48 hours before the day of arrival. After that, the first night is charged. No-shows are charged in full.'],
                'de' => ['Flexible Rate', 'Kostenfreie Stornierung bis 48 Stunden vor Anreise.', 'Kostenfreie Stornierung bis 48 Stunden vor dem Anreisetag. Danach wird die erste Nacht berechnet. Bei Nichtanreise wird der Gesamtbetrag berechnet.'],
                'fr' => ['Tarif flexible', 'Annulation gratuite jusqu’à 48 heures avant l’arrivée.', 'Annulation gratuite jusqu’à 48 heures avant le jour d’arrivée. Passé ce délai, la première nuit est facturée. En cas de non-présentation, la totalité est due.'],
                'nl' => ['Flexibel tarief', 'Gratis annuleren tot 48 uur voor aankomst.', 'Gratis annuleren tot 48 uur voor de aankomstdag. Daarna wordt de eerste nacht in rekening gebracht. Bij no-show wordt het volledige bedrag berekend.'],
            ]],
            ['SAVER', 'non_refundable', 'percent', -1200, false, 0, 20, [], [
                'en' => ['Saver rate', '12% off, paid at booking. Not refundable.', 'This rate is not refundable and cannot be changed. The full amount is charged at booking and is not returned if the stay is cancelled or shortened, for any reason.'],
                'de' => ['Sparrate', '12% günstiger, Zahlung bei Buchung. Nicht erstattbar.', 'Diese Rate ist nicht erstattbar und nicht umbuchbar. Der Gesamtbetrag wird bei der Buchung berechnet und bei Stornierung oder Verkürzung des Aufenthalts aus keinem Grund zurückerstattet.'],
                'fr' => ['Tarif économique', '12% de réduction, payé à la réservation. Non remboursable.', 'Ce tarif n’est ni remboursable ni modifiable. Le montant total est débité à la réservation et n’est restitué en aucun cas.'],
                'nl' => ['Voordeeltarief', '12% korting, betaald bij boeking. Niet restitueerbaar.', 'Dit tarief is niet restitueerbaar en niet wijzigbaar. Het volledige bedrag wordt bij de boeking in rekening gebracht en wordt om geen enkele reden terugbetaald.'],
            ]],
            ['EARLY', 'early_bird', 'percent', -800, true, 168, 15, ['min_days_before_arrival' => 30], [
                'en' => ['Early bird', '8% off when you book at least 30 days ahead.', 'Free cancellation up to 7 days before arrival. Booked at least 30 days in advance.'],
                'de' => ['Frühbucher', '8% günstiger ab 30 Tagen Vorlauf.', 'Kostenfreie Stornierung bis 7 Tage vor Anreise. Buchung mindestens 30 Tage im Voraus.'],
                'fr' => ['Réservation anticipée', '8% de réduction à partir de 30 jours à l’avance.', 'Annulation gratuite jusqu’à 7 jours avant l’arrivée. Réservation au moins 30 jours à l’avance.'],
                'nl' => ['Vroegboekkorting', '8% korting bij minstens 30 dagen vooruit boeken.', 'Gratis annuleren tot 7 dagen voor aankomst. Minstens 30 dagen vooraf geboekt.'],
            ]],
            ['LONGSTAY', 'long_stay', 'percent', -1500, true, 72, 12, ['min_nights' => 5], [
                'en' => ['Long stay', '15% off from five nights.', 'Free cancellation up to 72 hours before arrival. Applies to stays of five nights or more.'],
                'de' => ['Langzeitrate', '15% günstiger ab fünf Nächten.', 'Kostenfreie Stornierung bis 72 Stunden vor Anreise. Gilt ab fünf Nächten.'],
                'fr' => ['Long séjour', '15% de réduction à partir de cinq nuits.', 'Annulation gratuite jusqu’à 72 heures avant l’arrivée. À partir de cinq nuits.'],
                'nl' => ['Langverblijf', '15% korting vanaf vijf nachten.', 'Gratis annuleren tot 72 uur voor aankomst. Geldt vanaf vijf nachten.'],
            ]],
        ];

        foreach ($plans as [$code, $type, $adjType, $adjValue, $refundable, $hours, $priority, $extra, $translations]) {
            $plan = RatePlan::updateOrCreate(['code' => $code], $extra + [
                'type' => $type,
                'adjustment_type' => $adjType,
                'adjustment_value' => $adjValue,
                'refundable' => $refundable,
                'cancellation_hours' => $hours,
                'priority' => $priority,
                'includes_breakfast' => true,
                'is_active' => true,
            ]);

            foreach ($translations as $locale => [$name, $description, $policy]) {
                $plan->translations()->updateOrCreate(['locale' => $locale], [
                    'name' => $name,
                    'description' => $description,
                    'policy_text' => $policy,
                ]);
            }
        }
    }

    protected function extras(): void
    {
        // price is in cents; tax_rate in basis points (700 = 7% reduced,
        // 1900 = 19% standard — the German split for accommodation vs
        // everything else).
        $extras = [
            ['BREAKFAST', 1800, 'person_night', 700, 2, 1, false, [
                'en' => ['Breakfast buffet', 'Regional cheeses, fresh bread and eggs to order, served 07:00–10:30.'],
                'de' => ['Frühstücksbuffet', 'Regionale Käsesorten, frisches Brot und Eierspeisen, 07:00–10:30 Uhr.'],
                'fr' => ['Petit-déjeuner buffet', 'Fromages régionaux, pain frais et œufs à la demande, de 7h00 à 10h30.'],
                'nl' => ['Ontbijtbuffet', 'Regionale kazen, vers brood en eieren naar keuze, van 07:00 tot 10:30.'],
            ]],
            ['SPA', 2500, 'person', 1900, 4, 2, false, [
                'en' => ['Spa & sauna access', 'Finnish sauna, steam room and the indoor pool, towels included.'],
                'de' => ['Spa- & Saunazugang', 'Finnische Sauna, Dampfbad und Hallenbad, Handtücher inklusive.'],
                'fr' => ['Accès spa & sauna', 'Sauna finlandais, hammam et piscine intérieure, serviettes incluses.'],
                'nl' => ['Spa- & saunatoegang', 'Finse sauna, stoombad en binnenzwembad, handdoeken inbegrepen.'],
            ]],
            ['TRANSFER', 4500, 'stay', 1900, 2, 3, false, [
                'en' => ['Airport transfer', 'Private car from Munich airport, one way. Tell us your flight number.'],
                'de' => ['Flughafentransfer', 'Privatwagen ab Flughafen München, einfache Fahrt. Bitte Flugnummer angeben.'],
                'fr' => ['Transfert aéroport', 'Voiture privée depuis l’aéroport de Munich, aller simple. Indiquez votre vol.'],
                'nl' => ['Luchthaventransfer', 'Privéauto vanaf de luchthaven van München, enkele reis. Geef uw vluchtnummer door.'],
            ]],
            ['PARKING', 1200, 'night', 1900, 2, 4, false, [
                'en' => ['Garage parking', 'A reserved space in the underground garage.'],
                'de' => ['Garagenstellplatz', 'Reservierter Platz in der Tiefgarage.'],
                'fr' => ['Place de garage', 'Place réservée au garage souterrain.'],
                'nl' => ['Parkeerplaats in de garage', 'Gereserveerde plek in de ondergrondse garage.'],
            ]],
            ['COT', 1500, 'night', 700, 1, 5, false, [
                'en' => ['Cot for an infant', 'Set up before you arrive, linen included.'],
                'de' => ['Babybett', 'Vor Ihrer Ankunft aufgestellt, Bettwäsche inklusive.'],
                'fr' => ['Lit bébé', 'Installé avant votre arrivée, linge inclus.'],
                'nl' => ['Babybedje', 'Voor uw aankomst klaargezet, beddengoed inbegrepen.'],
            ]],
            ['LATE_CHECKOUT', 3000, 'stay', 1900, 1, 6, false, [
                'en' => ['Late checkout (16:00)', 'Subject to availability on the day.'],
                'de' => ['Später Checkout (16:00)', 'Nach Verfügbarkeit am Abreisetag.'],
                'fr' => ['Départ tardif (16h00)', 'Selon disponibilité le jour même.'],
                'nl' => ['Laat uitchecken (16:00)', 'Op basis van beschikbaarheid op de dag zelf.'],
            ]],
            // Shown as included rather than sold — the pool comes with the room.
            ['POOL', 0, 'stay', 0, 1, 7, true, [
                'en' => ['Indoor pool', 'Open 07:00–21:00, free for hotel guests.'],
                'de' => ['Hallenbad', 'Geöffnet 07:00–21:00 Uhr, für Hausgäste kostenfrei.'],
                'fr' => ['Piscine intérieure', 'Ouverte de 7h00 à 21h00, gratuite pour nos hôtes.'],
                'nl' => ['Binnenzwembad', 'Open van 07:00 tot 21:00, gratis voor hotelgasten.'],
            ]],
        ];

        foreach ($extras as [$code, $price, $per, $tax, $max, $sort, $included, $translations]) {
            $extra = Extra::updateOrCreate(['code' => $code], [
                'price' => $price,
                'applies_per' => $per,
                'tax_rate' => $tax,
                'max_quantity' => $max,
                'sort_order' => $sort,
                'is_included' => $included,
                'is_active' => true,
                'icon' => mb_strtolower($code),
            ]);

            foreach ($translations as $locale => [$name, $description]) {
                $extra->translations()->updateOrCreate(['locale' => $locale], [
                    'name' => $name,
                    'description' => $description,
                ]);
            }
        }

        // The cot only makes sense where a child fits.
        Extra::query()->where('code', 'COT')->first()?->roomTypes()->sync(
            RoomType::query()->whereIn('code', ['DBL', 'JSUITE'])->pluck('id')
        );
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
