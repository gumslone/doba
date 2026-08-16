@extends('layouts.app')

@section('content')
    @php
        use App\Support\Routing\Localization;
    @endphp

    <section class="mx-auto max-w-4xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('events.title') }}</h1>

        <ul class="mt-8 space-y-6">
            @forelse ($events as $event)
                <li class="flex gap-5 rounded-lg border border-neutral-200 p-5">
                    <div class="w-16 shrink-0 text-center">
                        <p class="text-2xl font-semibold" style="color: var(--doba-primary)">{{ $event->starts_at->format('d') }}</p>
                        <p class="text-sm uppercase text-neutral-500">{{ $event->starts_at->translatedFormat('M') }}</p>
                    </div>
                    <div>
                        <h2 class="text-lg font-medium">
                            <a href="{{ Localization::route('events.show', ['slug' => $event->slug()]) }}" class="hover:underline">
                                {{ $event->t('title') }}
                            </a>
                        </h2>
                        <p class="mt-0.5 text-sm text-neutral-500">
                            {{ $event->starts_at->translatedFormat('l, j F Y · H:i') }}
                            @if ($event->location) · {{ $event->location }} @endif
                        </p>
                        @if ($excerpt = $event->t('excerpt'))
                            <p class="mt-2 text-neutral-700">{{ $excerpt }}</p>
                        @endif
                    </div>
                </li>
            @empty
                <li class="text-neutral-600">—</li>
            @endforelse
        </ul>
    </section>
@endsection
