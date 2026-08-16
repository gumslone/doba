{{-- Inline SVG icons for the USP strip. Inline because four icons do not
     justify a sprite request, and no icon font can be lazy-loaded. --}}
@php $name = $name ?? 'check'; @endphp

<svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">
    @switch($name)
        @case('building')
            <path d="M3 20h18M5 20V9l7-5 7 5v11M10 20v-6h4v6" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
            @break
        @case('spa')
            <path d="M4 14c2-3 4-3 6 0M4 19c2-3 4-3 6 0M14 14c2-3 4-3 6 0M14 19c2-3 4-3 6 0M12 3v7M9 6l3-3 3 3"
                  stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            @break
        @case('dining')
            <path d="M7 3v8a2 2 0 002 2v8M7 3v5M11 3v5M17 3c-1.5 2-2 4-2 6s.5 3 2 3v9"
                  stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.4"/>
            <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            @break
        @case('wifi')
            <path d="M2 8.5a15 15 0 0120 0M5.5 12a10 10 0 0113 0M9 15.5a5 5 0 016 0" stroke="currentColor"
                  stroke-width="1.4" stroke-linecap="round"/>
            <circle cx="12" cy="19" r="1.3" fill="currentColor"/>
            @break
        @case('parking')
            <rect x="3.5" y="3.5" width="17" height="17" rx="2" stroke="currentColor" stroke-width="1.4"/>
            <path d="M9.5 17V7h3.2a3 3 0 010 6H9.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            @break
        @default
            <path d="M4 12.6l5.2 5.2L20 6.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    @endswitch
</svg>
