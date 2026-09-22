<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Booking\BookingService;
use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Support\Money;
use App\Support\Routing\Localization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rates for the hotel's Google Business Profile (docs/google-free-booking-links.md).
 *
 * Google shows a free link to the hotel's own site, with a price, if the
 * hotel enters its rates in its Business Profile — no partner, no feed.
 * The catch is keeping them in step with the website by hand. This page
 * is the column to copy from: per night, the lowest price a guest could
 * actually book on the website right now, as a final price including the
 * visitor's tax, because Google compares what it shows with what the
 * landing page charges.
 */
class AdminGoogleRatesController extends Controller
{
    public const DAYS = 90;

    public function index(AvailabilityService $availability): View
    {
        return view('admin.google-rates.index', [
            'days' => $this->days($availability),
            'bookingUrl' => Localization::route('booking.search', [], Localization::defaultLocale()),
            'guests' => 2,
        ]);
    }

    public function export(AvailabilityService $availability): StreamedResponse
    {
        $days = $this->days($availability);

        return response()->streamDownload(static function () use ($days): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['date', 'weekday', 'available', 'price_incl_tax', 'room_price', 'room_type']);

            foreach ($days as $day) {
                fputcsv($out, [
                    $day['date']->toDateString(),
                    $day['date']->format('D'),
                    $day['price'] === null ? 'no' : 'yes',
                    $day['price'] === null ? '' : Money::decimal($day['total']),
                    $day['price'] === null ? '' : Money::decimal($day['price']),
                    $day['room_type'] ?? '',
                ]);
            }

            fclose($out);
        }, 'doba-google-rates-'.CarbonImmutable::today(config('doba.timezone'))->toDateString().'.csv',
            ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @return array<int,array{date:CarbonImmutable,price:int|null,total:int|null,room_type:string|null}>
     */
    protected function days(AvailabilityService $availability): array
    {
        $from = CarbonImmutable::today(config('doba.timezone'));
        $to = $from->addDays(self::DAYS - 1);
        // Per person per night, so the tax for the two guests a Google
        // price is quoted for.
        $tax = BookingService::cityTax(2, 0, 1);

        $best = [];

        foreach (RoomType::query()->active()->ordered()->with('translations')->get() as $roomType) {
            // A type that cannot be had for one night is not a one-night price.
            if ($roomType->minNights() > 1 || $roomType->max_occupancy < 2) {
                continue;
            }

            foreach ($availability->calendar($roomType, $from, $to) as $day) {
                if (! $day['available'] || $day['price'] === null || $day['cta'] || $day['min_stay'] > 1) {
                    continue;
                }

                $price = $day['price'] + max(0, $roomType->cleaning_fee);

                if (! isset($best[$day['date']]) || $price < $best[$day['date']]['price']) {
                    $best[$day['date']] = ['price' => $price, 'room_type' => $roomType->t('name') ?? $roomType->code];
                }
            }
        }

        $days = [];

        for ($date = $from; $date <= $to; $date = $date->addDay()) {
            $hit = $best[$date->toDateString()] ?? null;

            $days[] = [
                'date' => $date,
                'price' => $hit['price'] ?? null,
                'total' => $hit === null ? null : $hit['price'] + $tax,
                'room_type' => $hit['room_type'] ?? null,
            ];
        }

        return $days;
    }
}
