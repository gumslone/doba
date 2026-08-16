@extends('admin.layout', ['title' => __('admin.pages')])

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('admin.pages') }}</h1>
        <a href="/admin/pages/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.new_page') }}</a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($pages as $page)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <a href="/admin/pages/{{ $page->id }}/edit" class="font-medium hover:underline">
                        {{ $page->t('title') ?? $page->code }}
                    </a>
                    <p class="text-sm text-neutral-500">
                        {{ $page->code }} ·
                        {{ implode(', ', $page->translatedLocales()) ?: '—' }}
                        @unless ($page->is_published) · <span class="text-amber-600">{{ __('admin.draft') }}</span> @endunless
                    </p>
                </div>
                <form method="POST" action="/admin/pages/{{ $page->id }}"
                      onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                </form>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">—</li>
        @endforelse
    </ul>
@endsection
