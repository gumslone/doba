@extends('admin.layout', ['title' => __('admin.events')])

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">
        {{ $event->exists ? ($event->t('title') ?? '#'.$event->id) : __('admin.new_event') }}
    </h1>

    <form method="POST" action="{{ $event->exists ? '/admin/events/'.$event->id : '/admin/events' }}" class="space-y-6">
        @csrf
        @if ($event->exists) @method('PUT') @endif

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-4">
            <div>
                <label for="starts_at" class="block text-sm font-medium">{{ __('admin.starts_at') }}</label>
                <input type="datetime-local" id="starts_at" name="starts_at" required
                       value="{{ old('starts_at', $event->starts_at?->format('Y-m-d\TH:i')) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="ends_at" class="block text-sm font-medium">{{ __('admin.ends_at') }}</label>
                <input type="datetime-local" id="ends_at" name="ends_at"
                       value="{{ old('ends_at', $event->ends_at?->format('Y-m-d\TH:i')) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="location" class="block text-sm font-medium">{{ __('admin.location') }}</label>
                <input type="text" id="location" name="location" value="{{ old('location', $event->location) }}"
                       placeholder="{{ __('admin.location_hint') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div class="flex items-end pb-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_published" value="0">
                    <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $event->is_published))>
                    {{ __('admin.published') }}
                </label>
            </div>
        </div>

        @include('admin.partials.locale-tabs', ['fieldsView' => 'admin.events.fields'])

        <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>
@endsection
