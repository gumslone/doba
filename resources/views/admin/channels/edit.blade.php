@extends('admin.layout', ['title' => $feed->exists ? $feed->name : __('admin.new_feed')])

@section('content')
    @php use App\Http\Controllers\Admin\AdminChannelController; @endphp

    <h1 class="mb-6 text-2xl font-semibold">{{ $feed->exists ? $feed->name : __('admin.new_feed') }}</h1>

    <form method="POST" action="{{ $feed->exists ? '/admin/channels/'.$feed->id : '/admin/channels' }}"
          class="max-w-2xl space-y-5">
        @csrf
        @if ($feed->exists) @method('PUT') @endif

        <div>
            <label class="block text-sm font-medium" for="name">{{ __('admin.name') }}</label>
            <input id="name" name="name" value="{{ old('name', $feed->name) }}" required
                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium" for="channel">{{ __('admin.channel') }}</label>
                <select id="channel" name="channel" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach (AdminChannelController::CHANNELS as $channel)
                        <option value="{{ $channel }}" @selected(old('channel', $feed->channel) === $channel)>
                            {{ __('admin.channel_'.$channel) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium" for="room_type_id">{{ __('admin.room_type') }}</label>
                <select id="room_type_id" name="room_type_id" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach ($roomTypes as $roomType)
                        <option value="{{ $roomType['id'] }}" @selected((int) old('room_type_id', $feed->room_type_id) === $roomType['id'])>
                            {{ $roomType['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium" for="import_url">{{ __('admin.import_url') }}</label>
            <input id="import_url" name="import_url" type="url" value="{{ old('import_url', $feed->import_url) }}"
                   class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm">
            <p class="mt-1 text-sm text-neutral-500">{{ __('admin.import_url_help') }}</p>
        </div>

        <label class="flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $feed->is_active ?? true))>
            <span class="text-sm">{{ __('admin.active') }}</span>
        </label>

        @if ($errors->any())
            <ul class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
            </ul>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white">{{ __('admin.save') }}</button>
            <a href="/admin/channels" class="px-5 py-2.5 text-neutral-600 hover:underline">{{ __('admin.cancel') }}</a>
        </div>
    </form>
@endsection
