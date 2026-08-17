@extends('install.layout', [
    'title' => __('install.step_database'),
    'intro' => __('install.database_intro'),
    'step' => 'database',
])

@section('content')
    <form method="POST" action="/install/database" class="space-y-5">
        @csrf

        <fieldset class="space-y-2">
            <label class="flex cursor-pointer items-start gap-3 rounded border border-neutral-200 p-3 has-[:checked]:border-neutral-900">
                <input type="radio" name="driver" value="sqlite" class="mt-1" @checked(old('driver', 'sqlite') === 'sqlite')>
                <span>
                    <span class="font-medium">{{ __('install.sqlite') }}</span>
                    <span class="block text-sm text-neutral-500">{{ __('install.sqlite_hint') }}</span>
                    <span class="mt-1 block font-mono text-xs text-neutral-400">{{ $suggested }}</span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-3 rounded border border-neutral-200 p-3 has-[:checked]:border-neutral-900">
                <input type="radio" name="driver" value="mysql" class="mt-1" @checked(old('driver') === 'mysql')>
                <span>
                    <span class="font-medium">{{ __('install.mysql') }}</span>
                    <span class="block text-sm text-neutral-500">{{ __('install.mysql_hint') }}</span>
                </span>
            </label>
        </fieldset>

        <div class="grid gap-3 rounded border border-neutral-200 p-4 sm:grid-cols-2">
            <div>
                <label for="host" class="block text-sm font-medium">{{ __('install.host') }}</label>
                <input id="host" name="host" value="{{ old('host', '127.0.0.1') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="port" class="block text-sm font-medium">{{ __('install.port') }}</label>
                <input id="port" name="port" type="number" value="{{ old('port', 3306) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="database" class="block text-sm font-medium">{{ __('install.database_name') }}</label>
                <input id="database" name="database" value="{{ old('database') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div>
                <label for="username" class="block text-sm font-medium">{{ __('install.username') }}</label>
                <input id="username" name="username" value="{{ old('username') }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div class="sm:col-span-2">
                <label for="password" class="block text-sm font-medium">{{ __('install.password') }}</label>
                <input id="password" name="password" type="password" autocomplete="off"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <p class="text-xs text-neutral-500 sm:col-span-2">{{ __('install.mysql_fields_hint') }}</p>
        </div>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="demo" value="1" class="mt-1" @checked(old('demo'))>
            <span>
                {{ __('install.demo') }}
                <span class="block text-xs text-neutral-500">{{ __('install.demo_hint') }}</span>
            </span>
        </label>

        <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('install.connect') }}</button>
        <p class="text-xs text-neutral-500">{{ __('install.database_slow') }}</p>
    </form>
@endsection
