@extends('admin.layout', ['title' => __('admin.new_booking')])

@section('content')
    <p class="mb-4 text-sm"><a href="/admin/front-desk" class="text-neutral-500 hover:underline">&larr; {{ __('admin.front_desk') }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.new_booking') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.new_booking_intro') }}</p>

    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="/admin/bookings" class="max-w-2xl space-y-5 rounded border border-neutral-200 bg-white p-5">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="room_type_id" class="block text-sm font-medium">{{ __('admin.room_type') }}</label>
                <select id="room_type_id" name="room_type_id" required class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach ($roomTypes as $type)
                        <option value="{{ $type->id }}" @selected(old('room_type_id') == $type->id)>{{ $type->t('name') ?? $type->code }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="check_in" class="block text-sm font-medium">{{ __('admin.check_in') }}</label>
                <input type="date" id="check_in" name="check_in" required value="{{ old('check_in', $checkIn) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="check_out" class="block text-sm font-medium">{{ __('admin.check_out') }}</label>
                <input type="date" id="check_out" name="check_out" required value="{{ old('check_out') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="adults" class="block text-sm font-medium">{{ __('admin.adults') }}</label>
                <input type="number" id="adults" name="adults" min="1" max="20" required value="{{ old('adults', 2) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="children" class="block text-sm font-medium">{{ __('admin.children') }}</label>
                <input type="number" id="children" name="children" min="0" max="20" value="{{ old('children', 0) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="units" class="block text-sm font-medium">{{ __('admin.units') }}</label>
                <input type="number" id="units" name="units" min="1" max="20" value="{{ old('units', 1) }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="source" class="block text-sm font-medium">{{ __('admin.booking_source') }}</label>
                <select id="source" name="source" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" @selected(old('source', 'phone') === $source)>{{ __('admin.source_'.$source) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="first_name" class="block text-sm font-medium">{{ __('admin.guest_first_name') }}</label>
                <input id="first_name" name="first_name" required maxlength="120" value="{{ old('first_name') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium">{{ __('admin.guest_last_name') }}</label>
                <input id="last_name" name="last_name" required maxlength="120" value="{{ old('last_name') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium">{{ __('admin.guest_email') }} <span class="font-normal text-neutral-500">({{ __('admin.optional') }})</span></label>
                <input type="email" id="email" name="email" maxlength="254" value="{{ old('email') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                <p class="mt-1 text-xs text-neutral-500">{{ __('admin.guest_email_hint') }}</p>
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium">{{ __('admin.guest_phone') }}</label>
                <input id="phone" name="phone" maxlength="64" value="{{ old('phone') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
        </div>

        <div>
            <label for="guest_notes" class="block text-sm font-medium">{{ __('admin.booking_guest_notes') }}</label>
            <textarea id="guest_notes" name="guest_notes" rows="2" maxlength="2000" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('guest_notes') }}</textarea>
        </div>
        <div>
            <label for="internal_notes" class="block text-sm font-medium">{{ __('admin.booking_internal_notes') }}</label>
            <textarea id="internal_notes" name="internal_notes" rows="2" maxlength="2000" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('internal_notes') }}</textarea>
        </div>

        <label class="flex items-start gap-2 text-sm">
            <input type="hidden" name="confirm" value="0">
            <input type="checkbox" name="confirm" value="1" checked class="mt-0.5">
            <span>{{ __('admin.booking_confirm_now') }} <span class="block text-xs text-neutral-500">{{ __('admin.booking_confirm_hint') }}</span></span>
        </label>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.booking_create') }}</button>
    </form>
@endsection
