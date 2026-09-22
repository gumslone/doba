@extends('layouts.app')

@section('content')
    @php use App\Support\Money; @endphp

    <section class="mx-auto max-w-4xl px-4 py-12">
        <div class="eyebrow">{{ $hotel->name }}</div>
        <h1 class="text-3xl font-semibold tracking-tight">{{ __('vouchers.title') }}</h1>
        <p class="lede mt-4" style="max-width:62ch">{{ __('vouchers.lede') }}</p>

        @if ($email = session('voucher_ordered'))
            <div class="mt-8 rounded border border-green-200 bg-green-50 p-5 text-green-900" role="status">
                <p class="font-medium">{{ __('vouchers.ordered_title') }}</p>
                <p class="mt-1">{{ __('vouchers.ordered_body', ['email' => $email]) }}</p>
            </div>
        @else
            <div class="mt-10 grid gap-10 lg:grid-cols-[3fr_2fr]">
                <form method="POST" action="{{ \App\Support\Routing\Localization::route('vouchers.store') }}" class="space-y-6">
                    @csrf
                    {{-- Invisible to people; a bot fills it in and is refused. --}}
                    <div style="position:absolute;left:-9999px" aria-hidden="true">
                        <label>Website <input type="text" name="website" tabindex="-1"></label>
                    </div>

                    <fieldset>
                        <legend class="block text-sm font-medium">{{ __('vouchers.amount') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($amounts as $i => $amount)
                                <label class="cursor-pointer rounded border border-neutral-300 px-4 py-2 text-sm has-[:checked]:border-neutral-900 has-[:checked]:bg-neutral-900 has-[:checked]:text-white">
                                    <input type="radio" name="amount" value="{{ $amount }}" class="sr-only" @checked((int) old('amount', $amounts[1] ?? $amounts[0] ?? 0) === $amount)>
                                    {{ Money::format($amount) }}
                                </label>
                            @endforeach
                        </div>
                        <label for="amount_other" class="mt-4 block text-sm text-neutral-600">{{ __('vouchers.amount_other') }} ({{ config('doba.currency') }})</label>
                        <input type="number" id="amount_other" name="amount_other" min="{{ $min / 100 }}" max="{{ $max / 100 }}" step="1" value="{{ old('amount_other') }}"
                               class="mt-1 w-40 rounded border border-neutral-300 px-3 py-2">
                        @error('amount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </fieldset>

                    <div>
                        <label for="recipient_name" class="block text-sm font-medium">{{ __('vouchers.recipient_name') }}</label>
                        <input type="text" id="recipient_name" name="recipient_name" maxlength="120" value="{{ old('recipient_name') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                        <p class="mt-1 text-xs text-neutral-500">{{ __('vouchers.recipient_hint') }}</p>
                    </div>

                    <div>
                        <label for="message" class="block text-sm font-medium">{{ __('vouchers.message') }}</label>
                        <textarea id="message" name="message" rows="3" maxlength="400" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">{{ old('message') }}</textarea>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('vouchers.message_hint') }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="buyer_name" class="block text-sm font-medium">{{ __('vouchers.buyer_name') }} *</label>
                            <input type="text" id="buyer_name" name="buyer_name" required maxlength="120" autocomplete="name" value="{{ old('buyer_name') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                            @error('buyer_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="buyer_email" class="block text-sm font-medium">{{ __('vouchers.buyer_email') }} *</label>
                            <input type="email" id="buyer_email" name="buyer_email" required maxlength="254" autocomplete="email" value="{{ old('buyer_email') }}" class="mt-1 w-full rounded border border-neutral-300 px-3 py-2">
                            @error('buyer_email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <button type="submit" class="btn-primary rounded px-6 py-3">{{ __('vouchers.order') }}</button>
                </form>

                <aside class="h-fit rounded-lg border border-neutral-200 p-6">
                    <h2 class="text-lg font-medium">{{ __('vouchers.how_title') }}</h2>
                    <ol class="mt-4 list-decimal space-y-3 pl-5 text-sm text-neutral-700">
                        <li>{{ __('vouchers.how_1') }}</li>
                        <li>{{ __('vouchers.how_2') }}</li>
                        <li>{{ __('vouchers.how_3') }}</li>
                    </ol>
                </aside>
            </div>
        @endif
    </section>
@endsection
