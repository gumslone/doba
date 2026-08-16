<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Models\Event;
use App\Models\RoomType;
use App\Support\Hotel\HotelSettings;
use App\Support\Hotel\Maps;
use Illuminate\Support\Arr;

/**
 * schema.org builders for the public site.
 *
 * Google reads Hotel, Room and Offer for hotel results; getting the Offer
 * right (price + priceCurrency + availability + validity window) is what
 * puts a price in the SERP next to an OTA listing that costs the hotel 15%.
 *
 * Every builder returns a plain array so a page can merge extra keys before
 * it is rendered, and so the shape is trivially assertable in a test.
 */
final class JsonLd
{
    /**
     * @return array<string,mixed>
     */
    public static function hotel(HotelSettings $hotel): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => config('doba.schema.type', 'Hotel'),
            '@id' => url('/').'#hotel',
            'name' => $hotel->name,
            'description' => $hotel->get('seo.description'),
            'url' => url('/'),
            'telephone' => $hotel->get('contact.phone'),
            'email' => $hotel->get('contact.email'),
            'priceRange' => config('doba.schema.price_range'),
            'currenciesAccepted' => config('doba.currency'),
            'checkinTime' => config('doba.checkin_from'),
            'checkoutTime' => config('doba.checkout_until'),
            'image' => array_filter([$hotel->ogImage()]),
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $hotel->get('contact.street'),
                'postalCode' => $hotel->get('contact.postal_code'),
                'addressLocality' => $hotel->get('contact.city'),
                'addressCountry' => $hotel->get('contact.country'),
            ]),
            'geo' => $hotel->hasCoordinates() ? [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $hotel->get('contact.latitude'),
                'longitude' => (float) $hotel->get('contact.longitude'),
            ] : null,
            'hasMap' => Maps::link($hotel),
            'sameAs' => array_values(array_filter([
                $hotel->get('social.facebook'),
                $hotel->get('social.instagram'),
                $hotel->get('social.tripadvisor'),
            ])),
            'amenityFeature' => self::amenities($hotel),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * A room type as schema.org/HotelRoom, with its "from" price as an Offer.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function room(RoomType $roomType, array $context = []): array
    {
        $locale = Arr::get($context, 'locale', app()->getLocale());

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'HotelRoom',
            '@id' => Arr::get($context, 'url').'#room',
            'name' => $roomType->t('name', $locale),
            'description' => $roomType->t('short_description', $locale)
                ?: $roomType->t('description', $locale),
            'url' => Arr::get($context, 'url'),
            'image' => Arr::get($context, 'images', []),
            'bed' => $roomType->bed_setup ? [
                '@type' => 'BedDetails',
                'typeOfBed' => $roomType->bed_setup,
            ] : null,
            'occupancy' => [
                '@type' => 'QuantitativeValue',
                'value' => $roomType->base_occupancy,
                'maxValue' => $roomType->max_occupancy,
                'unitCode' => 'C62', // UN/CEFACT code for "one" (a person)
            ],
            'floorSize' => $roomType->size_sqm ? [
                '@type' => 'QuantitativeValue',
                'value' => $roomType->size_sqm,
                'unitCode' => 'MTK', // square metre
            ] : null,
            'amenityFeature' => array_map(static fn (string $name): array => [
                '@type' => 'LocationFeatureSpecification',
                'name' => $name,
                'value' => true,
            ], $roomType->amenityNames()),
            'containedInPlace' => ['@id' => url('/').'#hotel'],
            'offers' => self::offer(
                $roomType->default_rate,
                Arr::get($context, 'url'),
                Arr::get($context, 'available', true)
            ),
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * An Offer for a nightly rate.
     *
     * Money arrives in integer minor units (§5) and is emitted as a decimal
     * string, because schema.org price is a plain number and 12500 cents
     * published as "12500" is a hotel advertising a €12,500 room.
     *
     * @return array<string,mixed>|null
     */
    public static function offer(?int $priceMinor, ?string $url = null, bool $available = true): ?array
    {
        if ($priceMinor === null) {
            return null;
        }

        return array_filter([
            '@type' => 'Offer',
            'price' => number_format($priceMinor / 100, 2, '.', ''),
            'priceCurrency' => config('doba.currency'),
            'url' => $url,
            'availability' => $available
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'priceValidUntil' => now()->addDays((int) config('doba.booking.booking_window_days'))->toDateString(),
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => number_format($priceMinor / 100, 2, '.', ''),
                'priceCurrency' => config('doba.currency'),
                'unitCode' => 'DAY',
                'referenceQuantity' => [
                    '@type' => 'QuantitativeValue',
                    'value' => 1,
                    'unitCode' => 'DAY',
                ],
            ],
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * A hotel happening as schema.org/Event — startDate is what qualifies
     * it for event search results, and the venue defaults to the hotel
     * itself since that is where most hotel events happen.
     *
     * @param  array<string,mixed>  $context  url, image
     * @return array<string,mixed>
     */
    public static function event(Event $event, HotelSettings $hotel, array $context = []): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->t('title'),
            'description' => $event->t('excerpt') ?: $event->t('meta_description'),
            'url' => Arr::get($context, 'url'),
            'image' => Arr::get($context, 'image'),
            'startDate' => $event->starts_at->toIso8601String(),
            'endDate' => $event->ends_at?->toIso8601String(),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => [
                '@type' => 'Place',
                'name' => $event->location ?: $hotel->name,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $hotel->get('contact.street'),
                    'postalCode' => $hotel->get('contact.postal_code'),
                    'addressLocality' => $hotel->get('contact.city'),
                    'addressCountry' => $hotel->get('contact.country'),
                ]),
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => $hotel->name,
                'url' => url('/'),
            ],
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<int,array{name:string,url:string|null}>  $crumbs
     * @return array<string,mixed>
     */
    public static function breadcrumbs(array $crumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (int $index, array $crumb): array => array_filter([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ], static fn ($value) => $value !== null),
                array_keys($crumbs),
                $crumbs
            ),
        ];
    }

    /**
     * @param  array<int,array{question:string,answer:string}>  $faqs
     * @return array<string,mixed>|null
     */
    public static function faqs(array $faqs): ?array
    {
        if ($faqs === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_values(array_map(static fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $faqs)),
        ];
    }

    /**
     * The site-level WebSite node, carrying the internal search action so
     * Google can offer a sitelinks searchbox for the booking funnel.
     *
     * @return array<string,mixed>
     */
    public static function website(HotelSettings $hotel, ?string $searchUrl = null): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => $hotel->name,
            'url' => url('/'),
            'inLanguage' => app()->getLocale(),
            // Only advertised once the booking funnel exists — a SearchAction
            // pointing at a route that is not built yet is a promise Google
            // will test and the site will fail.
            'potentialAction' => $searchUrl === null ? null : [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchUrl.'?check_in={check_in}&check_out={check_out}',
                ],
                'query-input' => [
                    'required name=check_in',
                    'required name=check_out',
                ],
            ],
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected static function amenities(HotelSettings $hotel): array
    {
        $amenities = $hotel->get('amenities.list');

        if (! is_array($amenities)) {
            return [];
        }

        return array_values(array_map(static fn ($name): array => [
            '@type' => 'LocationFeatureSpecification',
            'name' => (string) $name,
            'value' => true,
        ], $amenities));
    }
}
