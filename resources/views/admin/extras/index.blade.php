@extends('admin.layout', ['title' => __('admin.extras')])

@section('content')
    @php use App\Support\Money; @endphp

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('admin.extras') }}</h1>
        <a href="/admin/extras/create" class="rounded bg-neutral-900 px-4 py-2 text-sm text-white">{{ __('admin.new_extra') }}</a>
    </div>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($extras as $extra)
            <li class="flex items-center justify-between px-4 py-3">
                <div>
                    <a href="/admin/extras/{{ $extra->id }}/edit" class="font-medium hover:underline">
                        {{ $extra->t('name') ?? $extra->code }}
                    </a>
                    <p class="text-sm text-neutral-500">
                        {{ $extra->code }} ·
                        @if ($extra->is_included)
                            {{ __('extras.included') }}
                        @else
                            {{ Money::format($extra->price) }} {{ __($extra->applies_per->label()) }}
                        @endif
                        @unless ($extra->is_active) · <span class="text-amber-600">{{ __('admin.draft') }}</span> @endunless
                    </p>
                </div>
                <form method="POST" action="/admin/extras/{{ $extra->id }}"
                      onsubmit="return confirm('{{ __('admin.confirm_delete') }}')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('admin.delete') }}</button>
                </form>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">—</li>
        @endforelse
    </ul>
@endsection
