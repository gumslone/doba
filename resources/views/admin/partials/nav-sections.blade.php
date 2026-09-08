{{--
    The sidebar's link list. Rendered twice — once in the desktop rail,
    once inside the mobile <details> — from one array, so the two can
    never disagree about what exists.
--}}
@foreach ($sections as $group => $links)
    <p class="px-3 pb-1 pt-4 text-[0.68rem] font-semibold uppercase tracking-wider text-neutral-400">
        {{ $group }}
    </p>

    @foreach ($links as $link)
        @php [$href, $label, $pattern] = $link; $badge = (int) ($link[3] ?? 0); @endphp
        <a href="{{ $href }}"
           @if (request()->is($pattern)) aria-current="page" @endif
           @class([
               'block rounded px-3 py-1.5 text-sm',
               'bg-neutral-900 text-white' => request()->is($pattern),
               'text-neutral-700 hover:bg-neutral-100' => ! request()->is($pattern),
           ])>
            {{ $label }}
            @if ($badge > 0)
                <span class="ml-2 rounded-full bg-amber-500 px-1.5 py-0.5 text-[0.65rem] font-semibold text-white">{{ $badge }}</span>
            @endif
        </a>
    @endforeach
@endforeach
