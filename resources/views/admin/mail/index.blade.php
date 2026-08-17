@extends('admin.layout', ['title' => __('admin.mail')])

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.mail') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.mail_intro') }}</p>

    @if (session('mail_error'))
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
            {{ session('mail_error') }}
        </p>
    @endif

    {{-- The state that matters, said first and without hedging. --}}
    <div @class([
        'mb-6 rounded border p-4 text-sm',
        'border-green-200 bg-green-50 text-green-900' => $confirmed,
        'border-amber-200 bg-amber-50 text-amber-900' => ! $confirmed,
    ])>
        <p class="font-medium">{{ $confirmed ? __('admin.mail_working') : __('admin.mail_unconfirmed') }}</p>
        <p class="mt-1">{{ $confirmed ? __('admin.mail_working_hint') : __('admin.mail_unconfirmed_hint') }}</p>
    </div>

    @if ($pendingCode)
        <section class="mb-6 rounded border border-neutral-300 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.mail_sent_title', ['to' => session('mail_test_sent')]) }}</h2>
            <p class="mt-1 text-sm text-neutral-600">{{ __('admin.mail_sent_hint') }}</p>

            <form method="POST" action="/admin/mail/confirm" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="expected" value="{{ $pendingCode }}">
                <div>
                    <label for="code" class="block text-sm font-medium">{{ __('admin.mail_code_label') }}</label>
                    <input id="code" name="code" required autocomplete="off"
                           class="mt-1 rounded border border-neutral-300 px-3 py-2 font-mono uppercase">
                </div>
                <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">
                    {{ __('admin.mail_confirm') }}
                </button>
            </form>
        </section>
    @endif

    <form method="POST" action="/admin/mail" class="max-w-2xl space-y-5">
        @csrf
        @method('PUT')

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-2">
            <div>
                <label for="transport" class="block text-sm font-medium">{{ __('admin.mail_transport') }}</label>
                <select id="transport" name="transport" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach ($transports as $transport)
                        <option value="{{ $transport }}" @selected($settings['transport'] === $transport)>
                            {{ __('admin.mail_transport_'.$transport) }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-neutral-500">{{ __('admin.mail_transport_hint') }}</p>
            </div>

            <div>
                <label for="encryption" class="block text-sm font-medium">{{ __('admin.mail_encryption') }}</label>
                <select id="encryption" name="encryption" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach (['tls', 'ssl', 'none'] as $option)
                        <option value="{{ $option }}" @selected($settings['encryption'] === $option)>{{ strtoupper($option) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="host" class="block text-sm font-medium">{{ __('admin.mail_host') }}</label>
                <input id="host" name="host" value="{{ old('host', $settings['host']) }}"
                       placeholder="smtp.example.com"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>

            <div>
                <label for="port" class="block text-sm font-medium">{{ __('admin.mail_port') }}</label>
                <input id="port" name="port" type="number" value="{{ old('port', $settings['port']) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>

            <div>
                <label for="username" class="block text-sm font-medium">{{ __('admin.mail_username') }}</label>
                <input id="username" name="username" value="{{ old('username', $settings['username']) }}"
                       autocomplete="off" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium">{{ __('admin.mail_password') }}</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       placeholder="{{ $hasPassword ? __('admin.mail_password_set') : '' }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                <p class="mt-1 text-xs text-neutral-500">{{ __('admin.mail_password_hint') }}</p>
            </div>

            <div>
                <label for="from_address" class="block text-sm font-medium">{{ __('admin.mail_from_address') }}</label>
                <input id="from_address" name="from_address" type="email" required
                       value="{{ old('from_address', $settings['from_address']) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>

            <div>
                <label for="from_name" class="block text-sm font-medium">{{ __('admin.mail_from_name') }}</label>
                <input id="from_name" name="from_name" value="{{ old('from_name', $settings['from_name']) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
        </div>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>

    <form method="POST" action="/admin/mail/test" class="mt-8 flex max-w-2xl flex-wrap items-end gap-3 rounded border border-neutral-200 bg-white p-4">
        @csrf
        <div>
            <label for="to" class="block text-sm font-medium">{{ __('admin.mail_test_to') }}</label>
            <input id="to" name="to" type="email" required value="{{ $settings['from_address'] }}"
                   class="mt-1 rounded border border-neutral-300 px-3 py-2">
        </div>
        <button type="submit" class="rounded border border-neutral-300 px-5 py-2.5 hover:bg-neutral-50">
            {{ __('admin.mail_send_test') }}
        </button>
        @if ($settings['last_tested_at'])
            <p class="w-full text-xs text-neutral-500">
                {{ __('admin.mail_last_tested', ['when' => \Carbon\CarbonImmutable::parse($settings['last_tested_at'])->diffForHumans()]) }}
            </p>
        @endif
    </form>

    @if ($records !== [])
        <section class="mt-8 max-w-3xl rounded border border-neutral-200 bg-white p-5">
            <h2 class="font-medium">{{ __('admin.mail_dns', ['domain' => app(App\Support\Mail\MailSettings::class)->domain()]) }}</h2>
            {{-- Printed here because the alternative is discovering months
                 later that every confirmation went to spam. --}}
            <p class="mt-1 text-sm text-neutral-600">{{ __('admin.mail_dns_hint') }}</p>

            <div class="mt-4 space-y-4">
                @foreach ($records as $record)
                    <div class="text-sm">
                        <p class="font-mono text-xs text-neutral-500">{{ $record['type'] }} · {{ $record['host'] }}</p>
                        <code class="mt-1 block overflow-x-auto rounded bg-neutral-50 p-2 font-mono text-xs">{{ $record['value'] }}</code>
                        <p class="mt-1 text-xs text-neutral-500">{{ $record['note'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
