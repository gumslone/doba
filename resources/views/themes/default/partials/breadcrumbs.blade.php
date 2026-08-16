{{--
    The visible twin of the BreadcrumbList in JSON-LD. Google will use the
    markup for the SERP trail, but the visible trail is what a guest three
    clicks deep actually needs, and structured data describing navigation
    that does not exist on the page is the kind of mismatch that gets
    rich results withdrawn.
--}}
<nav aria-label="Breadcrumb" class="mx-auto max-w-6xl px-4 pt-6">
    <ol class="flex flex-wrap items-center gap-2 text-sm text-neutral-500">
        @foreach ($seo->getBreadcrumbs() as $index => $crumb)
            <li class="flex items-center gap-2">
                @if ($index > 0)
                    <span aria-hidden="true">/</span>
                @endif

                @if ($crumb['url'] && ! $loop->last)
                    <a href="{{ $crumb['url'] }}" class="hover:text-neutral-900 hover:underline">{{ $crumb['name'] }}</a>
                @else
                    <span aria-current="page" class="text-neutral-900">{{ $crumb['name'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
