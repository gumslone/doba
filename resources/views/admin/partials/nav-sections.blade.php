{{--
    The sidebar's link list. Rendered twice — once in the desktop rail,
    once inside the mobile <details> — from one array, so the two can
    never disagree about what exists.
--}}
@foreach ($sections as $group => $links)
    <p class="px-3 pb-1 pt-4 text-[0.68rem] font-semibold uppercase tracking-wider text-neutral-400">
        {{ $group }}
    </p>

    @foreach ($links as [$href, $label, $pattern])
        <a href="{{ $href }}"
           @if (request()->is($pattern)) aria-current="page" @endif
           @class([
               'block rounded px-3 py-1.5 text-sm',
               'bg-neutral-900 text-white' => request()->is($pattern),
               'text-neutral-700 hover:bg-neutral-100' => ! request()->is($pattern),
           ])>
            {{ $label }}
        </a>
    @endforeach
@endforeach
