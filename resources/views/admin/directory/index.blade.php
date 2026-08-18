@extends('admin.layout', ['title' => __('admin.directory')])

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.directory') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.directory_intro') }}</p>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif

    @if (session('update_error'))
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ session('update_error') }}</p>
    @endif

    <section class="mb-8 rounded border border-neutral-200 bg-white p-5">
        <form method="POST" action="/admin/directory" class="space-y-5">
            @csrf

            <label class="flex items-start gap-3">
                <input type="checkbox" name="enabled" value="1" @checked($enabled) class="mt-1">
                <span>
                    <span class="font-medium">{{ __('admin.directory_enable') }}</span>
                    <span class="block text-sm text-neutral-600">{{ __('admin.directory_enable_hint') }}</span>
                </span>
            </label>

            <div>
                <label for="hub" class="block text-sm font-medium">{{ __('admin.directory_hub') }}</label>
                <input id="hub" name="hub" type="url" value="{{ old('hub', $hub) }}" required
                       class="mt-1 w-full max-w-lg rounded border border-neutral-300 px-3 py-2">
                <p class="mt-1 text-xs text-neutral-500">{{ __('admin.directory_hub_hint') }}</p>
                @error('hub') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
        </form>
    </section>

    @unless ($httpsUrl)
        {{-- Said before it fails at the hub a week later, unwatched. --}}
        <p class="mb-6 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {{ __('admin.directory_needs_https') }}
        </p>
    @endunless

    @unless ($hasCoordinates)
        <p class="mb-6 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {{ __('admin.directory_needs_geo') }}
        </p>
    @endunless

    @if ($enabled)
        <section class="mb-8 rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.directory_announce') }}</h2>

            <p class="mt-2 text-sm text-neutral-600">
                @if ($lastAnnounce)
                    {{ __('admin.directory_last', [
                        'when' => $lastAnnounce['at'] ?? '—',
                        'result' => ($lastAnnounce['ok'] ?? false) ? __('admin.directory_ok') : ($lastAnnounce['error'] ?? __('admin.directory_failed')),
                    ]) }}
                @else
                    {{ __('admin.directory_never') }}
                @endif
            </p>

            <form method="POST" action="/admin/directory/announce" class="mt-4">
                @csrf
                <button type="submit" class="rounded border border-neutral-300 px-4 py-2">{{ __('admin.directory_announce_now') }}</button>
            </form>
        </section>
    @endif

    <section class="rounded border border-neutral-200 bg-white p-5">
        <h2 class="font-medium">{{ __('admin.directory_preview') }}</h2>
        <p class="mt-2 max-w-2xl text-sm text-neutral-600">{{ __('admin.directory_preview_hint') }}</p>

        <pre class="mt-4 max-h-96 overflow-auto rounded bg-neutral-900 p-4 font-mono text-xs text-neutral-100">{{ $preview }}</pre>
    </section>
@endsection
