@extends('admin.layout', ['title' => $enquiry->name])

@section('content')
    @php
        use App\Enums\EnquiryStatus;
        $field = 'mt-1 w-full rounded border border-neutral-300 px-3 py-2';
    @endphp

    <p class="mb-4 text-sm"><a href="/admin/enquiries" class="text-neutral-500 hover:underline">&larr; {{ __('admin.enquiries') }}</a></p>

    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ $enquiry->name }}</h1>
        <span class="text-sm text-neutral-500">
            {{ __('admin.enquiry_status_'.$enquiry->status->value) }} · {{ $enquiry->created_at?->format('Y-m-d H:i') }} · {{ strtoupper($enquiry->locale) }}
        </span>
    </div>

    @if (session('saved'))
        <p class="mb-6 rounded border border-green-200 bg-green-50 p-4 text-green-900">{{ session('saved') }}</p>
    @endif
    @if ($errors->any())
        <p class="mb-6 rounded border border-red-200 bg-red-50 p-4 text-red-900">{{ $errors->first() }}</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-[3fr_2fr]">
        <div class="space-y-6">
            <section class="rounded border border-neutral-200 bg-white p-5">
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
                    <dt class="text-neutral-500">{{ __('admin.enquiry_email') }}</dt>
                    <dd><a href="mailto:{{ $enquiry->email }}" class="underline">{{ $enquiry->email }}</a></dd>
                    @if ($enquiry->phone)
                        <dt class="text-neutral-500">{{ __('admin.enquiry_phone') }}</dt>
                        <dd><a href="tel:{{ preg_replace('/[^+\d]/', '', $enquiry->phone) }}" class="underline">{{ $enquiry->phone }}</a></dd>
                    @endif
                    @if ($enquiry->check_in)
                        <dt class="text-neutral-500">{{ __('admin.enquiry_dates') }}</dt>
                        <dd>
                            {{ $enquiry->check_in->toDateString() }}@if ($enquiry->check_out) → {{ $enquiry->check_out->toDateString() }}@endif
                            @if ($searchUrl)
                                · <a href="{{ $searchUrl }}" target="_blank" rel="noopener" class="underline">{{ __('admin.enquiry_check_availability') }} ↗</a>
                            @endif
                        </dd>
                    @endif
                    @if ($guest)
                        <dt class="text-neutral-500">{{ __('admin.enquiry_known_guest') }}</dt>
                        <dd>
                            <a href="/admin/guests/{{ $guest->id }}" class="underline">{{ $guest->last_name }}, {{ $guest->first_name }}</a>
                            · {{ trans_choice('admin.enquiry_stays', $guest->stays_count, ['count' => $guest->stays_count]) }}
                        </dd>
                    @endif
                </dl>

                <p class="mt-4 whitespace-pre-line border-t border-neutral-100 pt-4 text-neutral-800">{{ $enquiry->message }}</p>
            </section>

            @if ($enquiry->reply !== null)
                <section class="rounded border border-green-200 bg-green-50 p-5">
                    <h2 class="text-sm font-medium text-green-900">
                        {{ __('admin.enquiry_replied_by', [
                            'name' => $enquiry->repliedBy?->name ?? '—',
                            'when' => $enquiry->replied_at?->format('Y-m-d H:i') ?? '—',
                        ]) }}
                    </h2>
                    <p class="mt-2 whitespace-pre-line text-sm text-green-950">{{ $enquiry->reply }}</p>
                </section>
            @endif

            <section class="rounded border border-neutral-200 bg-white p-5">
                <h2 class="font-medium">{{ $enquiry->reply === null ? __('admin.enquiry_reply') : __('admin.enquiry_reply_again') }}</h2>

                @unless ($mailConfirmed)
                    <p class="mt-3 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        {{ __('admin.enquiry_mail_unconfirmed') }}
                        <a href="/admin/mail" class="underline">{{ __('admin.mail') }}</a>
                    </p>
                @endunless

                <form method="POST" action="/admin/enquiries/{{ $enquiry->id }}/reply" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="subject" class="block text-sm font-medium">{{ __('admin.enquiry_subject') }}</label>
                        <input id="subject" name="subject" maxlength="200" required value="{{ old('subject', $defaultSubject) }}" class="{{ $field }}">
                    </div>
                    <div>
                        <label for="body" class="block text-sm font-medium">{{ __('admin.enquiry_body') }}</label>
                        <textarea id="body" name="body" rows="8" maxlength="5000" required class="{{ $field }}"
                                  placeholder="{{ __('admin.enquiry_body_hint', ['name' => $enquiry->name]) }}">{{ old('body') }}</textarea>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('admin.enquiry_reply_hint', ['email' => $enquiry->email, 'locale' => strtoupper($enquiry->locale)]) }}</p>
                    </div>
                    <button type="submit" class="rounded bg-neutral-900 px-5 py-2.5 text-white" @disabled(! $mailConfirmed)>{{ __('admin.enquiry_send') }}</button>
                </form>
            </section>
        </div>

        <aside class="space-y-3 text-sm">
            @if ($enquiry->status !== EnquiryStatus::Spam)
                <form method="POST" action="/admin/enquiries/{{ $enquiry->id }}/status">
                    @csrf
                    <input type="hidden" name="status" value="spam">
                    <button type="submit" class="w-full rounded border border-neutral-300 bg-white px-4 py-2 text-left">{{ __('admin.enquiry_mark_spam') }}</button>
                </form>
            @else
                <form method="POST" action="/admin/enquiries/{{ $enquiry->id }}/status">
                    @csrf
                    <input type="hidden" name="status" value="read">
                    <button type="submit" class="w-full rounded border border-neutral-300 bg-white px-4 py-2 text-left">{{ __('admin.enquiry_not_spam') }}</button>
                </form>
            @endif
            @if ($enquiry->status === EnquiryStatus::Read)
                <form method="POST" action="/admin/enquiries/{{ $enquiry->id }}/status">
                    @csrf
                    <input type="hidden" name="status" value="new">
                    <button type="submit" class="w-full rounded border border-neutral-300 bg-white px-4 py-2 text-left">{{ __('admin.enquiry_mark_unread') }}</button>
                </form>
            @endif
            <form method="POST" action="/admin/enquiries/{{ $enquiry->id }}/delete" data-confirm="{{ __('admin.enquiry_delete_confirm') }}">
                @csrf
                <button type="submit" class="w-full rounded border border-red-200 bg-white px-4 py-2 text-left text-red-700">{{ __('admin.delete') }}</button>
            </form>
        </aside>
    </div>
@endsection
