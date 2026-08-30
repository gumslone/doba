{{-- The door a stay is pinned to, and the way to pin it (§5 phase 2).
     Rendered only once the hotel has listed doors at all — a hotel
     selling categories without numbers never sees this. --}}
@if ($hasRooms)
    <div class="mt-2 space-y-1">
        @foreach ($booking->rooms as $bookingRoom)
            <form method="POST" action="/admin/front-desk/{{ $booking->id }}/assign-room"
                  class="flex items-center gap-2 text-xs">
                @csrf
                <input type="hidden" name="booking_room_id" value="{{ $bookingRoom->id }}">

                @if ($bookingRoom->room)
                    <span class="rounded bg-neutral-900 px-1.5 py-0.5 font-mono text-white">
                        {{ $bookingRoom->room->number }}
                    </span>
                @else
                    {{-- Loud on purpose: an arrival with no door is the
                         thing the desk needs to fix before three o'clock. --}}
                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">{{ __('admin.no_room_yet') }}</span>
                @endif

                <select name="room_id" class="rounded border border-neutral-300 px-1.5 py-0.5">
                    <option value="">{{ __('admin.room_none') }}</option>
                    @foreach (app(App\Domain\FrontDesk\RoomAssignment::class)->optionsFor($bookingRoom) as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $bookingRoom->room_id)>
                            {{ $option->number }}@if ($option->floor) · {{ $option->floor }} @endif
                            @if ($option->status === 'dirty') ({{ __('admin.room_dirty') }}) @endif
                        </option>
                    @endforeach
                </select>

                <button type="submit" class="rounded border border-neutral-300 px-2 py-0.5">
                    {{ __('admin.room_assign') }}
                </button>
            </form>
        @endforeach
    </div>
@endif
