@extends('admin.layout', ['title' => __('admin.mail_wording')])

@section('content')
    <p class="mb-4 text-sm"><a href="/admin/mail" class="text-neutral-500 hover:underline">&larr; {{ __('admin.mail') }}</a></p>

    <div class="mb-6">
        <h1 class="text-2xl font-semibold">{{ __('admin.mail_wording') }}</h1>
        <p class="mt-1 max-w-2xl text-sm text-neutral-600">{{ __('admin.mail_wording_intro') }}</p>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif
    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="/admin/mail/wording" class="max-w-3xl space-y-6">
        @csrf
        @method('PUT')

        @foreach ($keys as $key => $placeholders)
            <section class="rounded border border-neutral-200 bg-white p-5">
                <h2 class="font-medium">{{ __('admin.mail_wording_key_'.$key) }}</h2>
                <p class="mt-1 text-xs text-neutral-500">
                    {{ __('admin.mail_wording_placeholders') }}
                    @foreach ($placeholders as $placeholder)
                        <code class="rounded bg-neutral-100 px-1">:{{ $placeholder }}</code>
                    @endforeach
                </p>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($locales as $locale)
                        <div>
                            <label class="block font-mono text-xs uppercase text-neutral-500" for="{{ $key }}_{{ $locale }}">{{ $locale }}</label>
                            <textarea id="{{ $key }}_{{ $locale }}" name="texts[{{ $key }}][{{ $locale }}]" rows="{{ str_ends_with($key, '_subject') || str_ends_with($key, '_heading') ? 1 : 3 }}" maxlength="1000"
                                      class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 text-sm"
                                      placeholder="{{ $texts[$key][$locale]['shipped'] }}">{{ old("texts.$key.$locale", $texts[$key][$locale]['custom']) }}</textarea>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <p class="text-xs text-neutral-500">{{ __('admin.mail_wording_hint') }}</p>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>
@endsection
