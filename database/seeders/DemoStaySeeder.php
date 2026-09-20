<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Enums\EnquiryStatus;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Enquiry;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Life for the public demo (§22): a front desk with nobody arriving and
 * a housekeeping list with no doors demonstrates an empty table.
 *
 * Every stay goes through BookingService — place, confirm, check in —
 * so the availability counters, the invoices and the guest book are
 * exactly what a real week would have left behind, not rows typed in.
 */
class DemoStaySeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today(config('doba.timezone'));
        $service = app(BookingService::class);

        $this->doors();

        // The calendar is generated from today forward; the stays that are
        // already in the house began before that, and a missing night is an
        // error by design (§5). Give last week its rows.
        foreach (RoomType::query()->get() as $roomType) {
            foreach (range(1, 10) as $daysAgo) {
                Availability::query()->firstOrCreate(
                    ['room_type_id' => $roomType->id, 'date' => $today->subDays($daysAgo)->toDateString()],
                    ['allotment' => $roomType->total_units],
                );
            }
        }

        $guests = [
            ['Anna', 'Kowalska', 'anna.kowalska@example.com', 'PL'],
            ['Jürgen', 'Müller', 'j.mueller@example.com', 'DE'],
            ['Sophie', 'Dubois', 'sophie.dubois@example.com', 'FR'],
            ['Oksana', 'Shevchenko', 'oksana@example.com', 'UA'],
            ['Pieter', 'de Vries', 'pieter@example.com', 'NL'],
            ['Emma', 'Clarke', 'emma.clarke@example.com', 'GB'],
            ['Marco', 'Rossi', 'marco.rossi@example.com', 'IT'],
        ];

        // [room type code, check-in offset, nights, end state, arrival time]
        $stays = [
            ['DBL', -9, 3, BookingStatus::CheckedOut, null],
            ['JSUITE', -6, 2, BookingStatus::CheckedOut, null],
            ['DBL', -2, 2, BookingStatus::CheckedIn, null],       // leaving today
            ['SGL', -1, 3, BookingStatus::CheckedIn, null],       // in the house
            ['DBL', 0, 2, BookingStatus::Confirmed, '14:30'],     // arriving today
            ['APT2', 0, 4, BookingStatus::Confirmed, '17:00'],    // arriving today
            ['JSUITE', 5, 3, BookingStatus::Confirmed, null],
        ];

        foreach ($stays as $i => [$code, $offset, $nights, $state, $arrival]) {
            $roomType = RoomType::query()->where('code', $code)->first();

            if ($roomType === null) {
                continue;
            }

            [$first, $last, $email, $country] = $guests[$i % count($guests)];

            try {
                $booking = $service->place(
                    $roomType, $today->addDays($offset), $today->addDays($offset + $nights),
                    ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'country' => $country],
                    adults: 2,
                );
            } catch (Throwable) {
                continue;   // a night without a row is a demo with one stay fewer, not a failed reset
            }

            $booking = $service->transition($booking, BookingStatus::Confirmed, 'demo');
            $booking->forceFill(['arrival_time' => $arrival, 'paid_amount' => $booking->deposit_due, 'balance_due' => $booking->total - $booking->deposit_due])->save();

            $this->door($booking);

            if (in_array($state, [BookingStatus::CheckedIn, BookingStatus::CheckedOut], true)) {
                $booking = $service->transition($booking, BookingStatus::CheckedIn, 'demo');
            }

            if ($state === BookingStatus::CheckedOut) {
                $booking = $service->transition($booking, BookingStatus::CheckedOut, 'demo');
                $this->review($booking, $i);
            }
        }

        Enquiry::create([
            'name' => 'Lena Hoffmann', 'email' => 'lena.hoffmann@example.com', 'locale' => 'de',
            'message' => "Guten Tag,\nhaben Sie in der ersten Augustwoche ein Apartment für zwei Erwachsene und zwei Kinder frei? Gibt es einen Parkplatz?",
            'check_in' => $today->addDays(40), 'check_out' => $today->addDays(47),
            'status' => EnquiryStatus::New,
        ]);

        Enquiry::create([
            'name' => 'Tom Baker', 'email' => 'tom.baker@example.com', 'locale' => 'en',
            'message' => 'Hi — is breakfast included in the flexible rate, and can we check in after 10pm?',
            'status' => EnquiryStatus::New,
        ]);
    }

    /** A few doors per category, so assignment and housekeeping have something to work on. */
    protected function doors(): void
    {
        $numbers = ['DBL' => ['101', '102', '103', '104'], 'JSUITE' => ['201', '202'], 'SGL' => ['105', '106'], 'APT2' => ['A1', 'A2']];

        foreach ($numbers as $code => $doors) {
            $roomType = RoomType::query()->where('code', $code)->first();

            foreach ($roomType === null ? [] : $doors as $number) {
                Room::query()->firstOrCreate(['number' => $number], [
                    'room_type_id' => $roomType->id,
                    'floor' => ctype_digit($number) ? $number[0] : 'Annex',
                    'status' => 'clean',
                ]);
            }
        }

        Room::query()->where('number', '106')->update(['status' => 'out_of_order', 'notes' => 'Shower being resealed']);
    }

    protected function door(Booking $booking): void
    {
        foreach ($booking->rooms as $bookingRoom) {
            $taken = BookingRoom::query()->whereNotNull('room_id')
                ->whereHas('booking', fn ($q) => $q->where('check_in', '<', $booking->check_out)->where('check_out', '>', $booking->check_in))
                ->pluck('room_id');

            $door = Room::query()
                ->where('room_type_id', $bookingRoom->room_type_id)
                ->where('status', '!=', 'out_of_order')
                ->whereNotIn('id', $taken)
                ->orderBy('number')
                ->first();

            if ($door !== null) {
                $bookingRoom->forceFill(['room_id' => $door->id])->save();
            }
        }
    }

    protected function review(Booking $booking, int $i): void
    {
        $texts = [
            [5, 'Exactly what we hoped for', 'Quiet room, the lake outside the window, and a breakfast that made us late for everything. Booking directly was painless.'],
            [4, 'Lovely house, would return', 'Warm welcome and a spotless room. The junior suite is worth it for the view alone.'],
        ];

        [$rating, $title, $body] = $texts[$i % count($texts)];

        $review = Review::create([
            'booking_id' => $booking->id, 'guest_id' => $booking->guest_id,
            'rating' => $rating, 'title' => $title, 'body' => $body, 'locale' => 'en',
        ]);

        $review->forceFill(['is_published' => true, 'published_at' => CarbonImmutable::now()])->save();
    }
}
