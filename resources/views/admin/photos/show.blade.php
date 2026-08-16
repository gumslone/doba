@extends('admin.layout', ['title' => __('admin.photos')])

@section('content')
    @php use App\Support\Routing\Localization; @endphp

    <p class="mb-2"><a href="/admin/photos" class="text-sm text-neutral-500 hover:underline">← {{ __('admin.photos') }}</a></p>
    <h1 class="mb-6 text-2xl font-semibold">{{ $title }}</h1>

    <form method="POST" action="/admin/photos/{{ $subject }}" enctype="multipart/form-data"
          class="mb-8 flex items-center gap-4 rounded border border-neutral-200 bg-white p-4">
        @csrf
        <input type="file" name="photos[]" multiple required accept="image/jpeg,image/png,image/webp"
               class="text-sm">
        <button type="submit" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.upload') }}</button>
        @error('photos.*') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    </form>

    <ul class="grid gap-6 sm:grid-cols-2">
        @forelse ($model->media as $photo)
            <li class="overflow-hidden rounded border border-neutral-200 bg-white">
                <img src="{{ $photo->url() }}" alt="{{ $photo->altFor() }}" loading="lazy"
                     width="{{ $photo->width }}" height="{{ $photo->height }}"
                     class="aspect-[3/2] w-full object-cover">

                <form method="POST" action="/admin/photos/{{ $subject }}/{{ $photo->id }}" class="space-y-3 p-4">
                    @csrf @method('PUT')

                    <div class="grid grid-cols-2 gap-2">
                        @foreach (Localization::locales() as $locale)
                            <label class="block text-xs">
                                <span class="font-medium uppercase text-neutral-500">{{ __('admin.alt') }} {{ $locale }}</span>
                                <input type="text" name="alt[{{ $locale }}]" maxlength="255"
                                       value="{{ $photo->alt[$locale] ?? '' }}"
                                       class="mt-0.5 w-full rounded border border-neutral-300 px-2 py-1">
                            </label>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between gap-3 text-sm">
                        <label class="flex items-center gap-1.5">
                            {{ __('admin.sort_order') }}
                            <input type="number" name="sort_order" min="0" value="{{ $photo->sort_order }}"
                                   class="w-16 rounded border border-neutral-300 px-2 py-1">
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input type="hidden" name="is_cover" value="0">
                            <input type="checkbox" name="is_cover" value="1" @checked($photo->is_cover)>
                            {{ __('admin.cover') }}
                        </label>
                        <button type="submit" class="rounded bg-neutral-900 px-3 py-1.5 text-white">{{ __('admin.save') }}</button>
                    </div>
                </form>

                <form method="POST" action="/admin/photos/{{ $subject }}/{{ $photo->id }}"
                      onsubmit="return confirm('{{ __('admin.confirm_delete') }}')"
                      class="border-t border-neutral-100 px-4 py-2 text-right">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                </form>
            </li>
        @empty
            <li class="text-neutral-500">—</li>
        @endforelse
    </ul>
@endsection
