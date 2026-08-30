@extends('admin.layout', ['title' => __('admin.rooms')])

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.rooms') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.rooms_intro') }}</p>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif

    @foreach ($roomTypes as $type)
        <section class="mb-8 rounded border border-neutral-200 bg-white">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-neutral-200 px-5 py-4">
                <h2 class="font-medium">{{ $type->t('name') ?? $type->code }}</h2>
                <span class="text-sm text-neutral-500">
                    {{ trans_choice('admin.rooms_listed', $type->rooms->count(), ['count' => $type->rooms->count(), 'units' => $type->total_units]) }}
                    @if ($type->rooms->isNotEmpty() && $type->rooms->count() !== $type->total_units)
                        {{-- Said, not fixed: the website sells total_units off
                             the grid whether or not the doors are listed, and
                             which of the two numbers is wrong is the
                             hotelier's call, not this page's. --}}
                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">
                            {{ __('admin.rooms_mismatch', ['units' => $type->total_units]) }}
                        </span>
                    @endif
                </span>
            </div>

            <ul class="divide-y divide-neutral-100">
                @foreach ($type->rooms as $door)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <span class="font-mono font-medium">{{ $door->number }}</span>
                            @if ($door->floor) <span class="text-neutral-500">· {{ $door->floor }}</span> @endif
                            @if ($door->notes) <span class="text-xs text-neutral-500">— {{ $door->notes }}</span> @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <form method="POST" action="/admin/rooms/{{ $door->id }}" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="notes" value="{{ $door->notes }}">
                                <select name="status" onchange="this.form.submit()" @class([
                                    'rounded border px-2 py-1 text-xs',
                                    'border-green-300 bg-green-50 text-green-800' => $door->status === 'clean',
                                    'border-amber-300 bg-amber-50 text-amber-900' => $door->status === 'dirty',
                                    'border-red-300 bg-red-50 text-red-800' => $door->status === 'out_of_order',
                                ])>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}" @selected($door->status === $status)>
                                            {{ __('admin.room_status_'.$status) }}
                                        </option>
                                    @endforeach
                                </select>
                            </form>

                            <form method="POST" action="/admin/rooms/{{ $door->id }}/delete"
                                  onsubmit="return confirm('{{ __('admin.room_delete_confirm', ['number' => $door->number]) }}')">
                                @csrf
                                <button type="submit" class="text-xs text-neutral-400 hover:text-red-600">{{ __('admin.delete') }}</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="/admin/rooms" class="flex flex-wrap items-end gap-3 border-t border-neutral-200 px-5 py-4">
                @csrf
                <input type="hidden" name="room_type_id" value="{{ $type->id }}">
                <div>
                    <label class="block text-xs text-neutral-500">{{ __('admin.room_number') }}</label>
                    <input name="number" required maxlength="32" class="mt-1 w-28 rounded border border-neutral-300 px-2 py-1.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-neutral-500">{{ __('admin.room_floor') }}</label>
                    <input name="floor" maxlength="32" class="mt-1 w-28 rounded border border-neutral-300 px-2 py-1.5 text-sm">
                </div>
                <button type="submit" class="rounded border border-neutral-300 px-3 py-1.5 text-sm">{{ __('admin.room_add') }}</button>
                @error('number') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </form>
        </section>
    @endforeach
@endsection
