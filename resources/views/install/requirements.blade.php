@extends('install.layout', [
    'title' => __('install.step_requirements'),
    'intro' => __('install.requirements_intro'),
    'step' => 'requirements',
])

@section('content')
    <ul class="divide-y divide-neutral-100 text-sm">
        @foreach ($checks as $check)
            <li class="py-2">
                <div class="flex items-center justify-between gap-3">
                    <span>
                        <span class="{{ $check['ok'] ? 'text-green-700' : 'text-red-700' }}">{{ $check['ok'] ? '✓' : '✕' }}</span>
                        <span class="font-mono">{{ $check['name'] }}</span>
                    </span>
                    <span class="text-neutral-500">{{ $check['detail'] }}</span>
                </div>
                @if ($check['fix'])
                    {{-- The fix, not just the fact: whoever is reading this
                         is the person who has to act on it. --}}
                    <p class="mt-1 rounded bg-red-50 p-2 text-xs text-red-800">{{ $check['fix'] }}</p>
                @endif
            </li>
        @endforeach
    </ul>

    <form method="POST" action="/install/requirements" class="mt-6 flex items-center gap-3">
        @csrf
        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.continue') }}</button>
        <a href="/install/requirements" class="text-sm text-neutral-500 hover:underline">{{ __('install.recheck') }}</a>
    </form>
@endsection
