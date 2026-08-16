<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\NoAvailabilityException;
use App\Domain\Invoicing\InvoiceRenderer;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Enums\BookingStatus;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\RoomType;
use App\Support\Hotel\HotelSettings;
use App\Support\Routing\Localization;
use App\Support\Seo\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The guest funnel: search → checkout → hold → pay → confirmation.
 *
 * Every step is a thin layer over the domain services (§17's one rule).
 * Nothing here decides availability, price or status for itself — it
 * translates HTTP into calls on AvailabilityService, BookingService and
 * PaymentService and renders what comes back.
 *
 * The whole funnel is noindex: robots.txt already disallows it, but a
 * crawler that ignores robots and walks the checkout would manufacture
 * holds against real inventory.
 */
class BookingController extends Controller
{
    public function search(Request $request, AvailabilityService $availability, Seo $seo, HotelSettings $hotel): View
    {
        $stay = $this->stayFrom($request);

        // The funnel is noindex, so these alternates are not for crawlers —
        // they keep the header's language switcher working mid-booking,
        // carrying the chosen dates across the switch.
        $seo->title(__('booking.search_title'))
            ->noindex()
            ->alternates(Localization::alternates(
                'booking.search',
                $stay === null ? [] : $this->stayQuery($stay)
            ));

        $error = $stay === null
            ? null
            : $availability->validateStay($stay['check_in'], $stay['check_out']);

        $offers = ($stay !== null && $error === null)
            ? $availability->search($stay['check_in'], $stay['check_out'], $stay['adults'], $stay['children'])
            : [];

        return view('booking.search', [
            'stay' => $stay,
            'error' => $error,
            'offers' => $offers,
            'hotel' => $hotel,
        ]);
    }

    public function checkout(Request $request, AvailabilityService $availability, Seo $seo): View|RedirectResponse
    {
        $stay = $this->stayFrom($request);
        $roomType = RoomType::query()->active()->find($request->integer('room_type'));

        if ($stay === null || $roomType === null) {
            return redirect(Localization::route('booking.search'));
        }

        $seo->title(__('booking.checkout_title'))
            ->noindex()
            ->alternates(Localization::alternates(
                'booking.checkout',
                $this->stayQuery($stay) + ['room_type' => $roomType->id]
            ));

        // Re-checked here, not trusted from the search page: the guest may
        // have sat on the results for an hour, and the funnel must never
        // offer a room the engine would refuse.
        if ($availability->validateStay($stay['check_in'], $stay['check_out']) !== null
            || ! $availability->isBookable($roomType, $stay['check_in'], $stay['check_out'], 1, $stay['adults'], $stay['children'])) {
            return redirect(Localization::route('booking.search', $this->stayQuery($stay)))
                ->with('booking_error', __('booking.error_gone'));
        }

        $plans = $availability->ratePlansFor($roomType, $stay['check_in'], $stay['check_out']);

        // The plan the guest picked on the room page, if it is still
        // eligible; otherwise the cheapest one. Never trust the query
        // string to name a plan the engine would refuse.
        $selected = collect($plans)->firstWhere('plan.id', $request->integer('rate_plan')) ?? ($plans[0] ?? null);

        return view('booking.checkout', [
            'stay' => $stay,
            'roomType' => $roomType,
            'plans' => $plans,
            'selected' => $selected,
            'total' => $selected['total']
                ?? $availability->stayPrice($roomType, $stay['check_in'], $stay['check_out']),
            'extras' => $roomType->availableExtras(),
        ]);
    }

    /**
     * Create the hold + pending booking, attach extras, and start payment.
     */
    public function store(
        StoreBookingRequest $request,
        BookingService $bookings,
        PaymentService $payments,
        AvailabilityService $availability,
    ): RedirectResponse {
        $validated = $request->validated();

        $stay = $this->stayFrom($request);
        $roomType = RoomType::query()->active()->findOrFail($validated['room_type']);

        if ($stay === null || $availability->validateStay($stay['check_in'], $stay['check_out']) !== null) {
            return redirect(Localization::route('booking.search'))
                ->with('booking_error', __('booking.error_range'));
        }

        // Re-resolved server-side: a posted plan id is a request, not a
        // fact, and an ineligible plan must never set the price.
        $ratePlan = null;

        if ($planId = ($validated['rate_plan'] ?? null)) {
            $eligible = collect($availability->ratePlansFor($roomType, $stay['check_in'], $stay['check_out']));
            $ratePlan = $eligible->firstWhere('plan.id', (int) $planId)['plan'] ?? null;
        }

        try {
            $booking = $bookings->place(
                $roomType,
                $stay['check_in'],
                $stay['check_out'],
                [
                    'email' => $validated['email'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'] ?? null,
                    'country' => $validated['country'] ?? null,
                    'marketing_consent' => (bool) ($validated['marketing_consent'] ?? false),
                ],
                adults: $stay['adults'],
                children: $stay['children'],
                sessionId: $request->session()->getId(),
                ratePlan: $ratePlan,
            );
        } catch (NoAvailabilityException) {
            // Someone else took the last room while this guest typed.
            return redirect(Localization::route('booking.search', $this->stayQuery($stay)))
                ->with('booking_error', __('booking.error_just_taken'));
        }

        if ($notes = ($validated['guest_notes'] ?? null)) {
            $booking->forceFill(['guest_notes' => $notes])->save();
        }

        if ($extras = array_filter($validated['extras'] ?? [])) {
            $bookings->addExtras($booking, array_map('intval', $extras));
        }

        // The deposit is charged against the current total, so payment is
        // initiated only after extras have been added.
        $payments->initiate(GatewayRegistry::default(), $booking->refresh());

        return redirect(Localization::route('booking.pay', ['reference' => $booking->reference]));
    }

    public function pay(string $reference, Seo $seo): View|RedirectResponse
    {
        $booking = $this->findByReference($reference);

        $seo->title(__('booking.pay_title'))
            ->noindex()
            ->alternates(Localization::alternates('booking.pay', compact('reference')));

        // Nothing left to pay — a refresh after payment lands here.
        if ($booking->status !== BookingStatus::Pending) {
            return redirect(Localization::route('booking.confirmation', ['reference' => $reference]));
        }

        $payment = $booking->payments()->latest('id')->first();

        return view('booking.pay', [
            'booking' => $booking->load('rooms.roomType.translations', 'extras.extra.translations'),
            'payment' => $payment,
            'gateway' => GatewayRegistry::default()->name(),
            // Where the guest continues for gateways that redirect
            // (PayPal, LiqPay, Coinbase); null for Stripe/manual.
            'approvalUrl' => $payment?->payload['links'][0]['href'] ?? $payment?->payload['approval_url'] ?? null,
            'holdExpiresAt' => $booking->holds()->unreleased()->min('expires_at'),
        ]);
    }

    public function confirmation(string $reference, Seo $seo): View
    {
        $booking = $this->findByReference($reference);

        $seo->title(__('booking.confirmation_title'))
            ->noindex()
            ->alternates(Localization::alternates('booking.confirmation', compact('reference')));

        return view('booking.confirmation', [
            'booking' => $booking->load('rooms.roomType.translations', 'extras.extra.translations', 'guest', 'invoice'),
        ]);
    }

    /**
     * The guest's self-service view (§11): no login, authorised by the
     * 40-character manage_token in the URL.
     */
    public function manage(string $reference, string $token, Seo $seo): View
    {
        $seo->title(__('booking.manage_title'))
            ->noindex()
            ->alternates(Localization::alternates('booking.manage', compact('reference', 'token')));

        return view('booking.manage', [
            'booking' => $this->findByToken($reference, $token)
                ->load('rooms.roomType.translations', 'extras.extra.translations', 'guest', 'invoice'),
            'token' => $token,
        ]);
    }

    /**
     * The guest's own copy of their invoice.
     *
     * Gated by the same manage token as the rest of this page — the PDF
     * carries their name and address, so it can never be served from a
     * public disk or a guessable URL.
     */
    public function invoice(string $reference, string $token, InvoiceRenderer $renderer): Response
    {
        $invoice = $this->findByToken($reference, $token)->invoice;

        if ($invoice === null) {
            throw new NotFoundHttpException('No invoice has been issued for this booking.');
        }

        if ($invoice->pdf_path === null || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            $renderer->store($invoice);
            $invoice->refresh();
        }

        return response(Storage::disk('local')->get($invoice->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"',
            // Never let a shared proxy keep a copy of somebody's invoice.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function cancel(string $reference, string $token, BookingService $bookings): RedirectResponse
    {
        $booking = $this->findByToken($reference, $token);

        if (! $booking->status->canTransitionTo(BookingStatus::Cancelled)) {
            return redirect(Localization::route('booking.manage', compact('reference', 'token')))
                ->with('booking_error', __('booking.error_not_cancellable'));
        }

        $bookings->transition($booking, BookingStatus::Cancelled, 'Cancelled by the guest');

        return redirect(Localization::route('booking.manage', compact('reference', 'token')))
            ->with('booking_cancelled', true);
    }

    protected function findByReference(string $reference): Booking
    {
        return Booking::query()->where('reference', $reference)->first()
            ?? throw new NotFoundHttpException("No booking [{$reference}].");
    }

    /**
     * Constant-time token comparison: the manage link is the only thing
     * standing between a guessed reference and someone else's booking.
     */
    protected function findByToken(string $reference, string $token): Booking
    {
        $booking = $this->findByReference($reference);

        if (! hash_equals($booking->manage_token, $token)) {
            throw new NotFoundHttpException('Invalid manage token.');
        }

        return $booking;
    }

    /**
     * @return array{check_in:CarbonImmutable,check_out:CarbonImmutable,adults:int,children:int}|null
     */
    protected function stayFrom(Request $request): ?array
    {
        $checkIn = $request->string('check_in')->toString();
        $checkOut = $request->string('check_out')->toString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
            return null;
        }

        return [
            'check_in' => CarbonImmutable::parse($checkIn)->startOfDay(),
            'check_out' => CarbonImmutable::parse($checkOut)->startOfDay(),
            'adults' => max(1, min(20, $request->integer('adults', 2))),
            'children' => max(0, min(20, $request->integer('children'))),
        ];
    }

    /**
     * @param  array{check_in:CarbonImmutable,check_out:CarbonImmutable,adults:int,children:int}  $stay
     * @return array<string,string|int>
     */
    protected function stayQuery(array $stay): array
    {
        return [
            'check_in' => $stay['check_in']->toDateString(),
            'check_out' => $stay['check_out']->toDateString(),
            'adults' => $stay['adults'],
            'children' => $stay['children'],
        ];
    }
}
