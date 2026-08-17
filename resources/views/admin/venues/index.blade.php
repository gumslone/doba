@extends('admin.layout', ['title' => __('admin.venues')])

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('admin.venues') }}</h1>
        <a href="/admin/venues/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.new_venue') }}</a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($venues as $venue)
            <li class="flex items-center justify-between gap-4 px-4 py-3">
                <div>
                    <a href="/admin/venues/{{ $venue->id }}/edit" class="font-medium hover:underline">
                        {{ $venue->t('name') ?? $venue->code }}
                    </a>
                    <p class="text-sm text-neutral-500">
                        {{ __('menu.type_'.$venue->type) }} ·
                        {{ trans_choice('admin.section_count', $venue->sections_count, ['count' => $venue->sections_count]) }}
                        @unless ($venue->is_active) · <span class="text-amber-600">{{ __('admin.draft') }}</span> @endunless
                    </p>
                </div>
                <form method="POST" action="/admin/venues/{{ $venue->id }}"
                      onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                </form>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">{{ __('admin.no_venues') }}</li>
        @endforelse
    </ul>
@endsection
