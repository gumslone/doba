@extends('admin.layout', ['title' => __('admin.update')])

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.update') }}</h1>
    <p class="mb-6 text-sm text-neutral-600">
        {{ __('admin.update_running', ['version' => $version]) }}
    </p>

    @if (session('update_result'))
        <div @class([
            'mb-6 rounded border p-4',
            'border-green-200 bg-green-50 text-green-900' => session('update_ok', true),
            'border-red-200 bg-red-50 text-red-900' => ! session('update_ok', true),
        ])>
            <ul class="space-y-1 text-sm">
                @foreach (session('update_result') as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ul>

            @if (session('update_restore'))
                <p class="mt-3 text-sm font-medium">{{ __('admin.update_restore_hint') }}</p>
                <code class="mt-1 block overflow-x-auto rounded bg-white/70 p-2 font-mono text-xs">{{ session('update_restore') }}</code>
            @endif
        </div>
    @endif

    @if (session('update_error') && ! session('update_result'))
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ session('update_error') }}</p>
    @endif

    <section class="mb-8 rounded border border-neutral-200 bg-white p-5">
        <h2 class="font-medium">{{ __('admin.pending_migrations') }}</h2>

        @if ($pending === [])
            <p class="mt-2 text-sm text-neutral-600">{{ __('admin.up_to_date') }}</p>
        @else
            <p class="mt-2 text-sm text-neutral-600">
                {{ trans_choice('admin.pending_count', count($pending), ['count' => count($pending)]) }}
            </p>
            <ul class="mt-3 space-y-1 font-mono text-xs text-neutral-600">
                @foreach ($pending as $migration)
                    <li>{{ $migration }}</li>
                @endforeach
            </ul>
        @endif

        @unless ($backupSupported)
            {{-- Said before the button, not after: updating without a
                 snapshot has to be a decision, never a discovery. --}}
            <p class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                {{ __('admin.update_no_backup', ['reason' => $backupReason]) }}
            </p>
        @endunless

        <form method="POST" action="/admin/update" class="mt-5 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="confirm" class="block text-sm font-medium">{{ __('admin.update_confirm_label') }}</label>
                <input id="confirm" name="confirm" required autocomplete="off" placeholder="UPDATE"
                       class="mt-1 rounded border border-neutral-300 px-3 py-2 font-mono">
                @error('confirm') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" @disabled(! $backupSupported)
                    class="rounded bg-neutral-900 px-5 py-2.5 text-white disabled:cursor-not-allowed disabled:opacity-40">
                {{ __('admin.run_update') }}
            </button>
        </form>

        <p class="mt-3 text-xs text-neutral-500">{{ __('admin.update_what_happens') }}</p>
    </section>

    <section class="rounded border border-neutral-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-medium">{{ __('admin.backups') }}</h2>
            <form method="POST" action="/admin/update/backup">
                @csrf
                <button type="submit" @disabled(! $backupSupported)
                        class="rounded border border-neutral-300 px-4 py-2 text-sm hover:bg-neutral-50 disabled:opacity-40">
                    {{ __('admin.take_backup') }}
                </button>
            </form>
        </div>

        <p class="mt-2 text-xs text-neutral-500">{{ __('admin.backups_hint') }} {{ __('admin.backups_nightly', ['at' => config('doba.backups.nightly_at')]) }}</p>

        <ul class="mt-4 divide-y divide-neutral-100">
            @forelse ($backups as $set)
                <li class="py-3 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span>
                            <span class="font-mono">{{ $set['stamp'] }}</span>
                            <span class="ml-2 text-neutral-500">
                                {{ $set['taken_at']->diffForHumans() }} · {{ round($set['size'] / 1024) }} KB ·
                                @if ($set['uploads'])
                                    {{ __('admin.backup_full') }}
                                @else
                                    {{-- Said out loud: a hotelier who thinks the photos are
                                         in there finds out otherwise at the worst moment. --}}
                                    <span class="text-amber-700">{{ __('admin.backup_database_only') }}</span>
                                @endif
                            </span>
                        </span>

                        <span class="flex items-center gap-3">
                            <a href="/admin/update/backups/{{ basename($set['database']) }}" class="hover:underline">
                                {{ __('admin.download_database') }}
                            </a>
                            @if ($set['uploads'])
                                <a href="/admin/update/backups/{{ basename($set['uploads']) }}" class="hover:underline">
                                    {{ __('admin.download_uploads') }}
                                </a>
                            @endif
                            <form method="POST" action="/admin/update/backups/{{ $set['stamp'] }}"
                                  onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                            </form>
                        </span>
                    </div>

                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs text-neutral-500">{{ __('admin.restore_this') }}</summary>
                        <form method="POST" action="/admin/update/restore" class="mt-2 flex flex-wrap items-end gap-2">
                            @csrf
                            <input type="hidden" name="stamp" value="{{ $set['stamp'] }}">
                            <div>
                                <label class="block text-xs text-neutral-600" for="confirm-{{ $set['stamp'] }}">
                                    {{ __('admin.restore_confirm_label', ['stamp' => $set['stamp']]) }}
                                </label>
                                <input id="confirm-{{ $set['stamp'] }}" name="confirm" required autocomplete="off"
                                       class="mt-1 rounded border border-neutral-300 px-2 py-1 font-mono text-xs">
                            </div>
                            <button type="submit" class="rounded border border-red-300 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50">
                                {{ __('admin.restore') }}
                            </button>
                        </form>
                        <p class="mt-2 text-xs text-neutral-500">{{ __('admin.restore_hint') }}</p>
                    </details>
                </li>
            @empty
                <li class="py-3 text-sm text-neutral-500">{{ __('admin.no_backups') }}</li>
            @endforelse
        </ul>
    </section>
@endsection
