@extends('install.layout', [
    'title' => __('install.step_finish'),
    'intro' => __('install.finish_intro'),
    'step' => 'finish',
])

@section('content')
    <div class="space-y-5 text-sm">
        <div>
            <p class="font-medium">{{ __('install.cron_title') }}</p>
            <p class="text-neutral-600">{{ __('install.cron_hint') }}</p>
            <code class="mt-1 block overflow-x-auto rounded bg-neutral-50 p-3 font-mono text-xs">{{ $cron }}</code>
        </div>

        <div>
            <p class="font-medium">{{ __('install.worker_title') }}</p>
            <p class="text-neutral-600">{{ __('install.worker_hint') }}</p>
            <code class="mt-1 block overflow-x-auto rounded bg-neutral-50 p-3 font-mono text-xs">{{ $worker }}</code>
        </div>

        {{-- The things that are not done yet, said now rather than
             discovered by a guest. --}}
        <div>
            <p class="font-medium">{{ __('install.checklist_title') }}</p>
            <ul class="mt-1 list-disc space-y-1 pl-5 text-neutral-600">
                <li>{{ __('install.checklist_mail') }}</li>
                <li>{{ __('install.checklist_payments') }}</li>
                <li>{{ __('install.checklist_tls') }}</li>
                <li>{{ __('install.checklist_backup') }}</li>
                <li>{{ __('install.checklist_test_booking') }}</li>
            </ul>
        </div>
    </div>

    <form method="POST" action="/install/finish" class="mt-6">
        @csrf
        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.finish_button') }}</button>
    </form>
@endsection
