<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\RoomType;
use App\Support\Api\Wire;
use App\Support\Hotel\HotelSettings;
use Illuminate\Http\JsonResponse;

/**
 * Discovery: who this hotel is, and what it sells (§17).
 */
class HotelController extends Controller
{
    public function show(HotelSettings $hotel): JsonResponse
    {
        return response()->json([
            'name' => $hotel->name,
            'locales' => (array) config('doba.locales', ['en']),
            'default_locale' => config('app.locale'),
            'currency' => config('doba.currency'),
            'timezone' => config('app.timezone'),
            'check_in_from' => config('doba.checkin_from'),
            'check_out_until' => config('doba.checkout_until'),
            'address' => array_filter([
                'street' => $hotel->get('contact.street'),
                'postal_code' => $hotel->get('contact.postal_code'),
                'city' => $hotel->get('contact.city'),
                'country' => $hotel->get('contact.country'),
            ]),
            'booking_window_days' => (int) config('doba.booking.booking_window_days'),
            'hold_minutes' => (int) config('doba.booking.hold_minutes'),
        ]);
    }

    public function roomTypes(): JsonResponse
    {
        $roomTypes = RoomType::query()
            ->active()
            ->ordered()
            ->with(['translations', 'media', 'amenities.translations'])
            ->get();

        return response()->json([
            'data' => array_map(static fn (RoomType $type): array => [
                'code' => $type->code,
                'names' => $type->translations->pluck('name', 'locale'),
                'slugs' => $type->translations->pluck('slug', 'locale'),
                'base_occupancy' => $type->base_occupancy,
                'max_occupancy' => $type->max_occupancy,
                'total_units' => $type->total_units,
                'size_sqm' => $type->size_sqm,
                'default_rate' => Wire::money($type->default_rate),
                'amenities' => $type->amenities->pluck('code')->values(),
                // array_map over the plain array rather than Collection::map:
                // the collection's TValue is invariant, so mapping a model
                // collection to arrays has no resolvable type.
                'images' => array_map(static fn (Media $m): array => [
                    'url' => $m->url(),
                    'width' => $m->width,
                    'height' => $m->height,
                ], $type->media->all()),
            ], $roomTypes->all()),
        ]);
    }
}
