@extends('admin.layout', ['title' => __('admin.api')])

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.api') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.api_intro') }}</p>

    @if ($freshSecret)
        {{-- The one and only sight of it. --}}
        <div class="mb-6 rounded border border-green-300 bg-green-50 p-4">
            <p class="font-medium text-green-900">{{ __('admin.api_secret_once') }}</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div>
                    <dt class="text-green-900">X-Api-Key-Id</dt>
                    <dd><code class="block overflow-x-auto rounded bg-white p-2 font-mono text-xs">{{ $freshKeyId }}</code></dd>
                </div>
                <div>
                    <dt class="text-green-900">X-Api-Secret</dt>
                    <dd><code class="block overflow-x-auto rounded bg-white p-2 font-mono text-xs">{{ $freshSecret }}</code></dd>
                </div>
            </dl>
            <p class="mt-3 text-sm text-green-900">{{ __('admin.api_secret_gone') }}</p>
        </div>
    @endif

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($clients as $client)
            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                <div>
                    <strong>{{ $client->name }}</strong>
                    @if ($client->sandbox)
                        <span class="ml-1 rounded bg-neutral-200 px-1.5 py-0.5 text-xs">{{ __('admin.api_sandbox') }}</span>
                    @endif
                    @unless ($client->isUsable())
                        <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">
                            {{ $client->revoked_at ? __('admin.api_revoked_label') : __('admin.api_expired') }}
                        </span>
                    @endunless
                    <p class="font-mono text-xs text-neutral-500">{{ $client->key_id }}</p>
                    <p class="text-xs text-neutral-500">
                        {{ implode(' · ', $client->scopes ?? []) }}
                        @if ($client->ip_allowlist) · {{ implode(', ', $client->ip_allowlist) }} @endif
                        @if ($client->last_used_at) · {{ __('admin.api_last_used', ['when' => $client->last_used_at->diffForHumans()]) }} @endif
                    </p>
                </div>

                @if ($client->isUsable())
                    <form method="POST" action="/admin/api/{{ $client->id }}"
                          onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-red-600 hover:underline">{{ __('admin.api_revoke') }}</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-sm text-neutral-500">{{ __('admin.api_none') }}</li>
        @endforelse
    </ul>

    <form method="POST" action="/admin/api" class="mt-8 max-w-2xl space-y-4 rounded border border-neutral-200 bg-white p-5">
        @csrf
        <h2 class="font-medium">{{ __('admin.api_new') }}</h2>

        <div>
            <label for="name" class="block text-sm font-medium">{{ __('admin.name') }}</label>
            <input id="name" name="name" required class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        </div>

        <fieldset>
            <legend class="text-sm font-medium">{{ __('admin.api_scopes') }}</legend>
            <p class="mb-2 text-xs text-neutral-500">{{ __('admin.api_scopes_hint') }}</p>
            <div class="grid gap-1 sm:grid-cols-2">
                @foreach ($scopes as $scope)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="scopes[]" value="{{ $scope }}">
                        <span class="font-mono text-xs">{{ $scope }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="expires_at" class="block text-sm font-medium">{{ __('admin.api_expires') }}</label>
                <input id="expires_at" name="expires_at" type="date" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="ip_allowlist" class="block text-sm font-medium">{{ __('admin.api_ips') }}</label>
                <input id="ip_allowlist" name="ip_allowlist" placeholder="203.0.113.4, 203.0.113.5"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
        </div>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="sandbox" value="1" class="mt-1">
            <span>
                {{ __('admin.api_sandbox') }}
                <span class="block text-xs text-neutral-500">{{ __('admin.api_sandbox_hint') }}</span>
            </span>
        </label>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.api_issue') }}</button>
    </form>

    @if (count($recent) > 0)
        <section class="mt-8 overflow-x-auto rounded border border-neutral-200 bg-white">
            <h2 class="border-b border-neutral-200 px-4 py-3 font-medium">{{ __('admin.api_recent') }}</h2>
            <table class="w-full text-xs">
                <tbody class="divide-y divide-neutral-100">
                    @foreach ($recent as $log)
                        <tr>
                            <td class="px-4 py-2 font-mono">{{ $log->method }} /{{ $log->path }}</td>
                            <td class="px-4 py-2 text-right">{{ $log->status }}</td>
                            <td class="px-4 py-2 text-right text-neutral-500">{{ $log->duration_ms }} ms</td>
                            <td class="px-4 py-2 font-mono text-neutral-400">{{ $log->request_id }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endsection
