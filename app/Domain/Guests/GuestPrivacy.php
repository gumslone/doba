<?php

declare(strict_types=1);

namespace App\Domain\Guests;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingRegistration;
use App\Models\Guest;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * What the hotel holds about a person, and how it lets go of it (§14).
 *
 * Two GDPR obligations, one deliberate asymmetry between them:
 *
 *  - **Export** hands over everything: the profile and every stay,
 *    because "everything you hold about me" means everything.
 *  - **Erasure** removes the person, not the business records. Invoices
 *    are kept under tax law for years regardless of who asks, and the
 *    booking's amounts and dates stay because occupancy and revenue
 *    reports must not rewrite history when a guest leaves it. What goes
 *    is everything that says WHO: the profile fields, and the notes on
 *    their bookings — a guest's "allergic to feathers" is exactly the
 *    kind of data erasure is for.
 */
class GuestPrivacy
{
    /**
     * The email an erased guest is left with.
     *
     * `.invalid` is reserved by RFC 2606, so nothing can ever deliver to
     * it — an anonymised guest must not keep receiving lifecycle mail.
     */
    public static function erasedEmail(Guest $guest): string
    {
        return 'erased-'.$guest->id.'@anonymised.invalid';
    }

    /**
     * Everything held about this person, as data they can take away.
     *
     * @return array<string,mixed>
     */
    public function export(Guest $guest): array
    {
        $guest->load(['bookings.rooms.roomType', 'bookings.extras.extra.translations', 'bookings.invoice', 'bookings.registration']);

        return [
            'exported_at' => CarbonImmutable::now()->toIso8601String(),
            'profile' => [
                'email' => $guest->email,
                'first_name' => $guest->first_name,
                'last_name' => $guest->last_name,
                'phone' => $guest->phone,
                'country' => $guest->country,
                'address' => $guest->address,
                'city' => $guest->city,
                'postal_code' => $guest->postal_code,
                'locale' => $guest->locale,
                'marketing_consent' => (bool) $guest->marketing_consent,
                'notes' => $guest->notes,
                'first_seen' => $guest->created_at?->toIso8601String(),
            ],
            'stays' => $guest->bookings->map(static fn (Booking $booking): array => [
                'reference' => $booking->reference,
                'status' => $booking->status->value,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'adults' => $booking->adults,
                'children' => $booking->children,
                'rooms' => $booking->rooms->map(static fn ($room): ?string => $room->roomType?->code)->all(),
                'extras' => $booking->extras->map(static fn ($extra): ?string => $extra->extra?->t('name'))->all(),
                'total' => $booking->total,
                'paid' => $booking->paid_amount,
                'currency' => $booking->currency,
                'guest_notes' => $booking->guest_notes,
                'invoice_number' => $booking->invoice?->number,
                // Theirs to see, like everything else held about them —
                // and the part they are most entitled to ask about.
                'registration_form' => $booking->registration?->party,
                'booked_at' => $booking->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Remove the person, keep the books.
     */
    public function erase(Guest $guest): void
    {
        $active = $guest->bookings()
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::CheckedIn])
            ->where('check_out', '>=', CarbonImmutable::today(config('doba.timezone'))->toDateString())
            ->exists();

        if ($active) {
            // Nobody can check in "Anonymised guest". The stay ends or is
            // cancelled first; erasure is the step after.
            throw new InvalidArgumentException('This guest has an upcoming or in-house stay. Erase after it ends or is cancelled.');
        }

        DB::transaction(function () use ($guest): void {
            $guest->bookings()->update([
                // The guest's own words about themselves go with them.
                'guest_notes' => null,
            ]);

            // And their words about us. A review is personal data twice
            // over — it is theirs, and it is signed with their name — so
            // an erased guest's review published under "Guest" is the
            // erasure not having happened.
            Review::query()->where('guest_id', $guest->id)->delete();

            // The registration forms: dates of birth and document numbers
            // of everybody who travelled with them. The most sensitive
            // thing held here, and nothing else refers to it.
            BookingRegistration::query()->whereIn('booking_id', $guest->bookings()->select('id'))->delete();

            $guest->forceFill([
                'email' => self::erasedEmail($guest),
                'first_name' => 'Guest',
                'last_name' => 'Anonymised',
                'phone' => null,
                'country' => null,
                'address' => null,
                'city' => null,
                'postal_code' => null,
                'notes' => null,
                'marketing_consent' => false,
                'anonymised_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    /**
     * The retention clock (§14): guests whose last stay ended more than
     * the configured number of months ago are anonymised unasked.
     *
     * This is the erasure nobody requests — data protection law expects
     * data to go when its purpose has, not when someone remembers to
     * write in. A returning guest simply becomes a new profile; the
     * hotel loses a "welcome back" it had no right to keep this long.
     */
    /**
     * Destroy registration forms whose stay ended longer ago than the
     * hotel is obliged to keep them. Far shorter than the guest book's
     * own clock: a name and a stay history is marketing data, a passport
     * number is a liability.
     */
    public function purgeRegistrations(): int
    {
        $cutoff = CarbonImmutable::today(config('doba.timezone'))
            ->subDays(max(1, (int) config('doba.checkin.retain_days', 365)))
            ->toDateString();

        return BookingRegistration::query()
            ->whereHas('booking', static fn ($q) => $q->where('check_out', '<', $cutoff))
            ->delete();
    }

    public function anonymiseDue(): int
    {
        $months = (int) config('doba.privacy.retention_months');

        if ($months <= 0) {
            return 0;   // switched off
        }

        $cutoff = CarbonImmutable::today(config('doba.timezone'))->subMonths($months);

        $due = Guest::query()
            ->whereNull('anonymised_at')
            // Every stay concluded, none of them recent, nothing booked
            // ahead. One future booking keeps the whole profile alive.
            ->whereDoesntHave('bookings', function ($query) use ($cutoff): void {
                $query->where('check_out', '>=', $cutoff->toDateString());
            })
            ->whereHas('bookings')
            ->get();

        $count = 0;

        foreach ($due as $guest) {
            try {
                $this->erase($guest);
                $count++;
            } catch (InvalidArgumentException) {
                // A stale future booking in a cancelled-ish state; skip.
            }
        }

        if ($count > 0) {
            Log::info('Retention: anonymised guests whose last stay is beyond the retention window.', [
                'count' => $count,
                'months' => $months,
            ]);
        }

        return $count;
    }
}
