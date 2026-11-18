<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Booking\BookingService;
use App\Domain\Booking\NoAvailabilityException;
use App\Domain\Guests\RegistrationRenderer;
use App\Domain\Payments\DeskGateway;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RoomType;
use App\Support\Hotel\Clock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

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
            $bookings->transition($booking, BookingStatus::Confirmed, 'Taken at the desk ('.$validated['source'].')', $request->user()?->id);
        } else {
            // "Hold it for a caller who will ring back" means until
            // tomorrow, not the twenty minutes a website checkout gets:
            // the hold used to expire while the clerk was still on the
            // phone, and nothing in the admin could confirm it afterwards.
            $booking->holds()->whereNull('released_at')->update([
                'expires_at' => now()->addHours((int) config('doba.booking.desk_hold_hours', 24)),
            ]);
        }

        return redirect('/admin/bookings/'.$booking->id.'/edit')
            ->with('saved', __('admin.booking_created', ['reference' => $booking->reference]));
    }

    public function edit(Booking $booking, PaymentService $payments): View
    {
        $booking->load(['guest', 'rooms.roomType.translations', 'extras.extra.translations', 'payments', 'statusHistory']);

        return view('admin.bookings.edit', [
            'booking' => $booking,
            'changeable' => $booking->status->inventorySide() !== 'none',
            // What each payment can still give back, so the page offers a
            // refund only where one is possible.
            'refundable' => $booking->payments->mapWithKeys(
                static fn (Payment $payment): array => [$payment->id => $payments->refundable($payment)]
            ),
            'holdExpiresAt' => $booking->status === BookingStatus::Pending
                ? $booking->holds()->whereNull('released_at')->min('expires_at')
                : null,
            'methods' => DeskGateway::METHODS,
            'today' => Clock::today(),
        ]);
    }

    /**
     * Money taken at the desk: cash, the terminal, a transfer (§8).
     */
    public function recordPayment(Request $request, Booking $booking, PaymentService $payments): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'method' => ['required', Rule::in(DeskGateway::METHODS)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $amount = (int) round(((float) $validated['amount']) * 100);

        try {
            $payments->recordAtDesk($booking, $amount, $validated['method'], $validated['note'] ?? null, $request->user()?->id);
        } catch (InvalidArgumentException) {
            return back()->withInput()->withErrors(['amount' => __('admin.booking_payment_too_much')]);
        }

        return back()->with('saved', __('admin.booking_payment_recorded', ['amount' => Money::format($amount, $booking->currency)]));
    }

    /**
     * Give money back, through whatever took it (§8).
     */
    public function refund(Request $request, Booking $booking, Payment $payment, PaymentService $payments): RedirectResponse
    {
        abort_unless($payment->booking_id === $booking->id, 404);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
        ]);

        $amount = (int) round(((float) $validated['amount']) * 100);

        try {
            $payments->refund(GatewayRegistry::make($payment->gateway), $payment, $amount);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['amount' => __('admin.booking_refund_too_much')]);
        } catch (Throwable $e) {
            // The provider said no, or cannot refund at all (crypto). Said
            // as it is: the money has not moved, and somebody must move it.
            report($e);

            return back()->withErrors(['amount' => __('admin.booking_refund_failed', ['error' => $e->getMessage()])]);
        }

        return back()->with('saved', __('admin.booking_refund_done', ['amount' => Money::format($amount, $booking->currency)]));
    }

    /**
     * A held booking the caller rang back about.
     */
    public function confirm(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->move($request, $booking, BookingStatus::Confirmed, $bookings, 'Confirmed at the desk', __('admin.booking_confirmed'));
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $reason = trim((string) ($validated['reason'] ?? ''));

        return $this->move($request, $booking, BookingStatus::Cancelled, $bookings,
            $reason !== '' ? 'Cancelled at the desk: '.$reason : 'Cancelled at the desk', __('admin.booking_cancelled'));
    }

    /**
     * The guest never came. The room stays theirs on the books — that is
     * what a no-show is — and nothing is refunded.
     */
    public function noShow(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        if ($booking->check_in->toDateString() > Clock::today()->toDateString()) {
            return back()->withErrors(['status' => __('admin.booking_no_show_early')]);
        }

        return $this->move($request, $booking, BookingStatus::NoShow, $bookings, 'No-show', __('admin.booking_no_show_done'));
    }

    protected function move(Request $request, Booking $booking, BookingStatus $to, BookingService $bookings, string $reason, string $message): RedirectResponse
    {
        if (! $booking->status->canTransitionTo($to)) {
            return back()->withErrors(['status' => __('admin.cannot_move', [
                'from' => __('booking.status_'.$booking->status->value),
                'to' => __('booking.status_'.$to->value),
            ])]);
        }

        try {
            $bookings->transition($booking, $to, $reason, $request->user()?->id);
        } catch (NoAvailabilityException $e) {
            return back()->withErrors(['status' => __('admin.booking_no_availability', ['date' => $e->date])]);
        }

        return back()->with('saved', $message);
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

    /**
     * The registration form the guest filled in online, for the signature.
     * Behind the admin session and never cached: it is the most sensitive
     * document this system can produce.
     */
    public function registration(Booking $booking, RegistrationRenderer $renderer): Response
    {
        abort_if($booking->registration === null, 404);

        $booking->load('rooms.room', 'rooms.roomType.translations');

        return response($renderer->render($booking), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="registration-'.$booking->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
