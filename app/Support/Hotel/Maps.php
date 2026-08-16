<?php

declare(strict_types=1);

namespace App\Support\Hotel;

/**
 * Google Maps URLs for the hotel's coordinates.
 *
 * Three shapes for three duties: the universal link for humans and for
 * schema.org hasMap, the directions link for the pre-arrival mail, and the
 * embed URL for the click-to-load iframe — which is the only one that
 * touches the visitor's browser with Google, and only after they ask.
 */
final class Maps
{
    public static function coordinates(HotelSettings $hotel): ?string
    {
        if (! $hotel->hasCoordinates()) {
            return null;
        }

        return $hotel->get('contact.latitude').','.$hotel->get('contact.longitude');
    }

    public static function link(HotelSettings $hotel): ?string
    {
        $at = self::coordinates($hotel);

        return $at === null ? null
            : 'https://www.google.com/maps/search/?api=1&query='.urlencode($at);
    }

    public static function directions(HotelSettings $hotel): ?string
    {
        $at = self::coordinates($hotel);

        return $at === null ? null
            : 'https://www.google.com/maps/dir/?api=1&destination='.urlencode($at);
    }

    public static function embed(HotelSettings $hotel): ?string
    {
        $at = self::coordinates($hotel);

        return $at === null ? null
            : 'https://maps.google.com/maps?q='.urlencode($at).'&z=15&output=embed';
    }
}
