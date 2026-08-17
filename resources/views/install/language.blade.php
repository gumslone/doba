@extends('install.layout', ['title' => __('install.step_language'), 'step' => 'language'])

@section('content')
    <form method="POST" action="/install/language" class="space-y-5">
        @csrf
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($locales as $locale)
                <label class="flex cursor-pointer items-center gap-2 rounded border border-neutral-200 p-3 hover:bg-neutral-50 has-[:checked]:border-neutral-900">
                    <input type="radio" name="locale" value="{{ $locale }}" @checked($loop->first)>
                    <span>{{ \Locale::getDisplayLanguage($locale, $locale) ?: $locale }}</span>
                </label>
            @endforeach
        </div>
        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.continue') }}</button>
    </form>
@endsection
