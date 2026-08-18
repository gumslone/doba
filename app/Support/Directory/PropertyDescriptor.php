<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Models\Gallery;
use App\Models\Media;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Venue;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What this hotel tells an aggregator about itself (§21).
 *
 * The rule that keeps this honest: **everything in here is already on the
 * public website.** The name, the address, the photos, the room types and
 * the price a room starts at are what any guest sees on the home page.
 * Nothing about bookings, occupancy, guests or how badly November is
 * selling ever appears — a hotel should be able to read this document in
 * full and find nothing it would not have printed in a brochure.
 *
 * It is also not a second source of truth. Every field is read from the
 * same settings and models the website renders from, so a descriptor that
 * disagrees with the site is impossible rather than merely unlikely.
 */
class PropertyDescriptor
{
    /**
     * The document's own schema version, not Doba's.
     *
     * A hub will be reading installs that are years apart in software
     * version, so it needs to know the shape of what it just fetched
     * without inferring it from a release number.
     */
    public const VERSION = 1;

    public function __construct(private readonly HotelSettings $hotel) {}

    /**
     * Off until a hotelier turns it on.
     *
     * The setting wins over the config default, because this is a
     * business decision rather than a deployment one: listing a hotel is
     * the hotelier's to make from their own admin, not something an
     * operator sets in a `.env` on their behalf. The env value is only
     * the starting position for a fleet that wants one.
     */
    public static function isEnabled(): bool
    {
        $setting = app(HotelSettings::class)->get('directory.enabled');

        return $setting === null
            ? (bool) config('doba.directory.enabled')
            : (bool) $setting;
    }

    public static function hub(): string
    {
        $setting = app(HotelSettings::class)->get('directory.hub');

        return is_string($setting) && $setting !== ''
            ? $setting
            : (string) config('doba.directory.hub');
    }

    /**
     * A stable identity for this install, minted once.
     *
     * Not derived from the domain: a hotel that moves from
     * hotel-alpenrose.at to alpenrose.tirol is the same business, and a
     * hub that treated it as a new one would lose its history and list it
     * twice during the changeover.
     */
    public static function installId(): string
    {
        $existing = app(HotelSettings::class)->get('directory.install_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        Setting::put('directory', 'install_id', $id);
        app(HotelSettings::class)->refresh();

        return $id;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $locale = (string) config('app.locale');

        return [
            'doba_directory_version' => self::VERSION,
            'install_id' => self::installId(),
            'software' => ['name' => 'doba', 'version' => $this->softwareVersion()],
            'url' => rtrim((string) config('app.url'), '/'),
            'updated_at' => $this->updatedAt()->toIso8601String(),

            'property' => [
                'name' => $this->hotel->name,
                'type' => (string) config('doba.schema.type', 'Hotel'),
                'description' => $this->text('seo.description'),
                'tagline' => $this->text('general.tagline'),
                'price_range' => (string) config('doba.schema.price_range'),
                'currency' => (string) config('doba.currency'),
                'timezone' => (string) config('app.timezone'),
                'locales' => array_values((array) config('doba.locales', ['en'])),
                'default_locale' => $locale,
                'check_in_from' => (string) config('doba.checkin_from'),
                'check_out_until' => (string) config('doba.checkout_until'),
                'address' => (object) array_filter([
                    'street' => $this->hotel->get('contact.street'),
                    'postal_code' => $this->hotel->get('contact.postal_code'),
                    'city' => $this->hotel->get('contact.city'),
                    'country' => $this->hotel->get('contact.country'),
                ]),
                // The one field a search-by-map cannot be built without,
                // and the one most likely to be missing: a hotel that has
                // not set it is listed, just not placed.
                'geo' => $this->hotel->hasCoordinates() ? [
                    'latitude' => (float) $this->hotel->get('contact.latitude'),
                    'longitude' => (float) $this->hotel->get('contact.longitude'),
                ] : null,
                // Business contacts, the ones already printed in the
                // footer and required in most jurisdictions' imprint.
                'contact' => (object) array_filter([
                    'email' => $this->hotel->get('contact.email'),
                    'phone' => $this->hotel->get('contact.phone'),
                ]),
                'images' => $this->images(),
            ],

            'room_types' => $this->roomTypes(),
            'amenities' => $this->amenities(),

            'endpoints' => [
                // Where a guest goes. Deliberately first: the aggregator's
                // job ends by handing the guest to the hotel.
                'website' => rtrim((string) config('app.url'), '/'),
                'booking' => Localization::route('booking.search', [], $locale),
                // Live prices for a set of dates, no credentials needed.
                'quote' => url('/api/directory/quote'),
                'openapi' => url('/openapi.json'),
                'api' => url('/api/v1'),
            ],

            'capabilities' => [
                'live_quote' => true,
                // A partner API exists, but a key for it is the hotel's to
                // grant. A hub should not assume it can call /api/v1.
                'partner_api' => true,
                'ical' => (bool) config('doba.features.ota_sync'),
                // Read off the data rather than a flag: a hub filtering
                // for "has a restaurant" wants hotels that have one, not
                // hotels that have the feature switched on and no venue.
                'restaurant' => Venue::query()->active()->exists(),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function roomTypes(): array
    {
        $types = RoomType::query()
            ->active()
            ->ordered()
            ->with(['translations', 'media', 'amenities'])
            ->get();

        return array_map(function (RoomType $type): array {
            $cover = $type->coverImage();

            return [
                'code' => $type->code,
                'names' => (object) $type->translations->pluck('name', 'locale')->all(),
                'urls' => (object) $type->translations
                    ->mapWithKeys(fn ($t): array => [
                        $t->locale => Localization::route('rooms.show', ['slug' => $t->slug], $t->locale),
                    ])->all(),
                'base_occupancy' => $type->base_occupancy,
                'max_occupancy' => $type->max_occupancy,
                'size_sqm' => $type->size_sqm,
                // The published rate, which is what the website shows when
                // no dates are chosen. Not a live price — that is what the
                // quote endpoint is for, and conflating the two is how an
                // aggregator ends up advertising a rate nobody can book.
                'from_rate' => [
                    'amount' => (int) $type->default_rate,
                    'currency' => (string) config('doba.currency'),
                ],
                'amenities' => $type->amenities->pluck('code')->values()->all(),
                'image' => $cover?->url(),
            ];
        }, $types->all());
    }

    /**
     * @return array<int,string>
     */
    protected function amenities(): array
    {
        $list = $this->hotel->get('amenities.list');

        return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
    }

    /**
     * The hotel's own gallery, in the order it chose.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function images(): array
    {
        // `where`, not Gallery::hotel(): that one firstOrCreates, and a
        // public GET should never write a row.
        $gallery = Gallery::query()->where('code', Gallery::HOTEL)->with('media')->first();

        if ($gallery === null) {
            return [];
        }

        return array_map(static fn (Media $m): array => [
            'url' => $m->url(),
            'width' => $m->width,
            'height' => $m->height,
        ], $gallery->media->sortBy('sort_order')->take(12)->values()->all());
    }

    /**
     * When any of this last changed, so a hub can poll cheaply.
     *
     * Settings, room types and their photos are the three things this
     * document is built from; the newest touch across them is the honest
     * answer, and it is what the ETag and Last-Modified are cut from.
     */
    public function updatedAt(): CarbonImmutable
    {
        $stamps = array_filter([
            Setting::query()->max('updated_at'),
            RoomType::query()->max('updated_at'),
            Media::query()->max('updated_at'),
        ]);

        $newest = $stamps === [] ? null : max(array_map(
            static fn ($t): CarbonImmutable => CarbonImmutable::parse($t),
            $stamps,
        ));

        return $newest ?? CarbonImmutable::now();
    }

    protected function text(string $key): ?string
    {
        $value = $this->hotel->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function softwareVersion(): string
    {
        $file = base_path('VERSION');

        return is_file($file) ? trim((string) file_get_contents($file)) : 'dev';
    }
}
