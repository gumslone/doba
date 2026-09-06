<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Booking\BookingService;
use App\Domain\Booking\NoAvailabilityException;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Bookings the desk makes and changes (§12).
 *
 * The phone call and the walk-in are most of a small hotel's business,
 * and until now every one of them had to be typed into the public
 * funnel. This goes through BookingService::place — the same locking,
 * pricing and restriction checks as the website — because a booking
 * the desk can take without those checks is a booking that oversells.
 */
class AdminBookingController extends Controller
{
    public const SOURCES = ['phone', 'walk_in', 'email'];

    public function create(Request $request): View
    {
        return view('admin.bookings.create', [
            'roomTypes' => RoomType::query()->active()->ordered()->with('translations')->get(),
            'sources' => self::SOURCES,
            'checkIn' => $request->query('date', CarbonImmutable::today(config('doba.timezone'))->toDateString()),
        ]);
    }

    public function store(Request $request, BookingService $bookings): RedirectResponse
    {
        $validated = $request->validate([
            'room_type_id' => ['required', 'exists:room_types,id'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'units' => ['nullable', 'integer', 'min:1', 'max:20'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'source' => ['required', Rule::in(self::SOURCES)],
            'guest_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $roomType = RoomType::query()->findOrFail($validated['room_type_id']);

        // A phone booking may well come without an email. The guest row
        // is keyed by email, so one is minted that can never receive
        // anything: nothing must go out to an address nobody gave us.
        $email = trim((string) ($validated['email'] ?? ''));
        $email = $email !== '' ? $email : 'desk-'.bin2hex(random_bytes(6)).'@no-email.invalid';

        try {
            $booking = $bookings->place(
                $roomType,
                CarbonImmutable::parse($validated['check_in']),
                CarbonImmutable::parse($validated['check_out']),
                [
                    'email' => $email,
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'] ?? null,
                ],
                adults: (int) $validated['adults'],
                children: (int) ($validated['children'] ?? 0),
                units: (int) ($validated['units'] ?? 1),
                locale: (string) config('app.locale'),
            );
        } catch (NoAvailabilityException $e) {
            return back()->withInput()->withErrors(['check_in' => __('admin.booking_no_availability', ['date' => $e->date])]);
        }

        $booking->forceFill([
            'source' => $validated['source'],
            'guest_notes' => $validated['guest_notes'] ?? null,
            'internal_notes' => $validated['internal_notes'] ?? null,
        ])->save();

        if ((bool) ($validated['confirm'] ?? true)) {
            // A desk booking is a promise already made on the phone: it
            // holds nothing, it is booked. Pending is for a guest who has
            // not paid yet, and this guest will pay at the desk.
            $bookings->transition($booking, BookingStatus::Confirmed, 'Taken at the desk ('.$validated['source'].')');
        }

        return redirect('/admin/bookings/'.$booking->id.'/edit')
            ->with('saved', __('admin.booking_created', ['reference' => $booking->reference]));
    }

    public function edit(Booking $booking): View
    {
        return view('admin.bookings.edit', [
            'booking' => $booking->load(['guest', 'rooms.roomType.translations', 'extras.extra.translations', 'payments', 'statusHistory']),
            'changeable' => $booking->status->inventorySide() !== 'none',
        ]);
    }

    public function update(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $validated = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'guest_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $datesChanged = $validated['check_in'] !== $booking->check_in->toDateString()
            || $validated['check_out'] !== $booking->check_out->toDateString()
            || (int) $validated['adults'] !== $booking->adults
            || (int) ($validated['children'] ?? 0) !== $booking->children;

        if ($datesChanged) {
            try {
                $bookings->changeStay(
                    $booking,
                    CarbonImmutable::parse($validated['check_in']),
                    CarbonImmutable::parse($validated['check_out']),
                    (int) $validated['adults'],
                    (int) ($validated['children'] ?? 0),
                );
            } catch (NoAvailabilityException $e) {
                return back()->withInput()->withErrors(['check_in' => __('admin.booking_no_availability', ['date' => $e->date])]);
            } catch (InvalidArgumentException $e) {
                return back()->withInput()->withErrors(['check_in' => $e->getMessage()]);
            }
        }

        $booking->fresh()->forceFill([
            'arrival_time' => $validated['arrival_time'] ?? null,
            'guest_notes' => $validated['guest_notes'] ?? null,
            'internal_notes' => $validated['internal_notes'] ?? null,
        ])->save();

        return back()->with('saved', __('admin.booking_saved'));
    }
}
