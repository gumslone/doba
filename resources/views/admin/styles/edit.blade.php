@extends('admin.layout', ['title' => __('admin.styles')])

@section('content')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('admin.styles') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-neutral-600">{{ __('admin.styles_hint') }}</p>

    <form method="POST" action="/admin/styles" class="max-w-2xl space-y-6">
        @csrf
        @method('PUT')

        {{-- Presets are token bundles, not theme forks: every one of them
             renders the same markup, so a hotelier can change the whole
             look and still receive every upstream fix. --}}
        <fieldset class="rounded border border-neutral-200 bg-white p-4">
            <legend class="px-1 text-sm font-medium">{{ __('admin.style_preset') }}</legend>
            <p class="mb-4 text-xs text-neutral-500">{{ __('admin.style_preset_hint') }}</p>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($presets as $id => $preset)
                    <label class="flex cursor-pointer items-start gap-3 rounded border border-neutral-200 p-3 hover:bg-neutral-50 has-[:checked]:border-neutral-900 has-[:checked]:bg-neutral-50">
                        <input type="radio" name="preset" value="{{ $id }}" class="mt-1"
                               data-swatch="{{ implode(',', $preset['swatch']) }}"
                               @checked(old('preset', $current['preset']) === $id)>
                        <span>
                            <span class="flex items-center gap-2">
                                <span class="font-medium">{{ $preset['label'] }}</span>
                                <span class="flex overflow-hidden rounded-full border border-neutral-300">
                                    @foreach ($preset['swatch'] as $colour)
                                        <span class="block h-4 w-4" style="background: {{ $colour }}"></span>
                                    @endforeach
                                </span>
                            </span>
                            <span class="mt-0.5 block text-xs text-neutral-500">{{ __($preset['description']) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('preset') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-2">
            <p class="text-xs text-neutral-500 sm:col-span-2">{{ __('admin.style_override_hint') }}</p>
            <div>
                <label for="color_primary" class="block text-sm font-medium">{{ __('admin.color_primary') }}</label>
                <input type="color" id="color_primary" name="color_primary" value="{{ old('color_primary', $current['color_primary']) }}"
                       class="mt-1 h-10 w-full rounded border border-neutral-300">
                @error('color_primary') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="color_accent" class="block text-sm font-medium">{{ __('admin.color_accent') }}</label>
                <input type="color" id="color_accent" name="color_accent" value="{{ old('color_accent', $current['color_accent']) }}"
                       class="mt-1 h-10 w-full rounded border border-neutral-300">
            </div>
            <div>
                <label for="font_heading" class="block text-sm font-medium">{{ __('admin.font_heading') }}</label>
                <select id="font_heading" name="font_heading" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach ($fontStacks as $key => $stack)
                        <option value="{{ $key }}" style="font-family: {{ $stack }}" @selected(old('font_heading', $current['font_heading']) === $key)>
                            {{ ucfirst($key) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="font_body" class="block text-sm font-medium">{{ __('admin.font_body') }}</label>
                <select id="font_body" name="font_body" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                    @foreach ($fontStacks as $key => $stack)
                        <option value="{{ $key }}" style="font-family: {{ $stack }}" @selected(old('font_body', $current['font_body']) === $key)>
                            {{ ucfirst($key) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="rounded border border-neutral-200 bg-white p-4">
            <label for="custom_css" class="block text-sm font-medium">{{ __('admin.custom_css') }}</label>
            <p class="mt-1 text-xs text-neutral-500">{{ __('admin.custom_css_hint') }}</p>
            <textarea id="custom_css" name="custom_css" rows="10" spellcheck="false"
                      class="mt-2 w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm">{{ old('custom_css', $current['custom_css']) }}</textarea>
            @error('custom_css') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>
@endsection
