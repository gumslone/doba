@extends('admin.layout', ['title' => __('admin.reviews')])

@section('content')
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('admin.reviews') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-neutral-600">{{ __('admin.reviews_intro') }}</p>
        </div>
        @if ($aggregate)
            <div class="text-sm text-neutral-600">
                ★ <strong>{{ number_format($aggregate['average'], 1) }}</strong>
                · {{ trans_choice('admin.reviews_count', $aggregate['count'], ['count' => $aggregate['count']]) }}
            </div>
        @endif
    </div>

    @unless ($enabled)
        <p class="mb-6 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {{ __('admin.reviews_disabled') }}
        </p>
    @endunless

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif

    @foreach ([['heading' => __('admin.reviews_pending'), 'rows' => $pending, 'draft' => true],
               ['heading' => __('admin.reviews_published'), 'rows' => $published, 'draft' => false]] as $group)
        <section class="mb-8 rounded border border-neutral-200 bg-white">
            <h2 class="border-b border-neutral-200 px-5 py-4 font-medium">{{ $group['heading'] }}</h2>

            <ul class="divide-y divide-neutral-100">
                @forelse ($group['rows'] as $review)
                    <li class="px-5 py-4 text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div>
                                <span aria-hidden="true">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
                                @if ($review->title)<strong class="ml-2">{{ $review->title }}</strong>@endif
                            </div>
                            <span class="text-xs text-neutral-500">
                                {{ $review->guest?->last_name }}, {{ $review->guest?->first_name }}
                                · <span class="font-mono">{{ $review->booking?->reference }}</span>
                                · {{ $review->created_at?->toDateString() }} · {{ $review->locale }}
                            </span>
                        </div>

                        <p class="mt-2 whitespace-pre-line text-neutral-700">{{ $review->body }}</p>

                        <form method="POST" action="/admin/reviews/{{ $review->id }}/respond" class="mt-3">
                            @csrf
                            <label class="block text-xs text-neutral-500">{{ __('admin.review_response_label') }}</label>
                            <textarea name="hotel_response" rows="2" maxlength="2000"
                                      class="mt-1 w-full rounded border border-neutral-300 px-3 py-1.5 text-sm"
                                      placeholder="{{ __('admin.review_response_hint') }}">{{ $review->hotel_response }}</textarea>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="submit" class="rounded border border-neutral-300 px-3 py-1.5 text-xs">{{ __('admin.review_respond') }}</button>
                            </div>
                        </form>

                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($group['draft'])
                                <form method="POST" action="/admin/reviews/{{ $review->id }}/publish">@csrf
                                    <button type="submit" class="rounded bg-neutral-900 px-3 py-1.5 text-xs text-white">{{ __('admin.review_publish') }}</button>
                                </form>
                            @else
                                <form method="POST" action="/admin/reviews/{{ $review->id }}/unpublish">@csrf
                                    <button type="submit" class="rounded border border-neutral-300 px-3 py-1.5 text-xs">{{ __('admin.review_unpublish') }}</button>
                                </form>
                            @endif
                            <form method="POST" action="/admin/reviews/{{ $review->id }}/delete"
                                  onsubmit="return confirm('{{ __('admin.review_delete_confirm') }}')">@csrf
                                <button type="submit" class="text-xs text-neutral-400 hover:text-red-600">{{ __('admin.delete') }}</button>
                            </form>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-neutral-500">{{ __('admin.reviews_none') }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach

    <div class="mt-4">{{ $published->links() }}</div>
@endsection
