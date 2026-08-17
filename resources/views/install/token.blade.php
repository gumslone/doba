@extends('install.layout', ['title' => __('install.token_title'), 'intro' => __('install.token_intro')])

@section('content')
    {{-- The path, never the value. Printing the token on the page that
         asks for it would make the gate decorative. --}}
    <p class="rounded border border-neutral-200 bg-neutral-50 p-3 font-mono text-sm break-all">{{ $tokenPath }}</p>

    <form method="POST" action="/install" class="mt-5 space-y-4">
        @csrf
        <div>
            <label for="token" class="block text-sm font-medium">{{ __('install.token_label') }}</label>
            <input id="token" name="token" required autocomplete="off" autofocus
                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono">
        </div>
        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.continue') }}</button>
    </form>
@endsection
