@extends('install.layout', ['title' => __('install.repair_title'), 'intro' => __('install.repair_intro')])

@section('content')
    {{-- Deliberately no "install anyway" button: exactly one marker is
         present, and a fresh install from here would migrate over a
         hotel's live reservations. --}}
    <ul class="space-y-2 text-sm">
        <li>{{ $hasLock ? '✓' : '✕' }} {{ __('install.marker_lock', ['path' => $lockPath]) }}</li>
        <li>{{ $hasRecord ? '✓' : '✕' }} {{ __('install.marker_record') }}</li>
    </ul>

    <p class="mt-5 text-sm text-neutral-600">
        {{ $hasRecord ? __('install.repair_missing_lock', ['path' => $lockPath]) : __('install.repair_missing_record') }}
    </p>
@endsection
