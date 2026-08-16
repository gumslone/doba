@extends('admin.layout', ['title' => __('admin.pages')])

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">
        {{ $page->exists ? ($page->t('title') ?? $page->code) : __('admin.new_page') }}
    </h1>

    <form method="POST" action="{{ $page->exists ? '/admin/pages/'.$page->id : '/admin/pages' }}" class="space-y-6">
        @csrf
        @if ($page->exists) @method('PUT') @endif

        <div class="grid gap-4 rounded border border-neutral-200 bg-white p-4 sm:grid-cols-4">
            <div>
                <label for="code" class="block text-sm font-medium">{{ __('admin.code') }}</label>
                <input type="text" id="code" name="code" required value="{{ old('code', $page->code) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm">
            </div>
            <div>
                <label for="sort_order" class="block text-sm font-medium">{{ __('admin.sort_order') }}</label>
                <input type="number" id="sort_order" name="sort_order" min="0" value="{{ old('sort_order', $page->sort_order ?? 0) }}"
                       class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
            </div>
            <div class="flex items-end gap-4 pb-2 sm:col-span-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_published" value="0">
                    <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $page->is_published))>
                    {{ __('admin.published') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="show_in_menu" value="0">
                    <input type="checkbox" name="show_in_menu" value="1" @checked(old('show_in_menu', $page->show_in_menu))>
                    {{ __('admin.in_menu') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="noindex" value="0">
                    <input type="checkbox" name="noindex" value="1" @checked(old('noindex', $page->noindex))>
                    noindex
                </label>
            </div>
        </div>

        @include('admin.partials.locale-tabs', ['fieldsView' => 'admin.pages.fields'])

        <button type="submit" class="rounded bg-neutral-900 px-6 py-2.5 text-white">{{ __('admin.save') }}</button>
    </form>
@endsection
