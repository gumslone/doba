@extends('admin.layout', ['title' => __('admin.invoices')])

@section('content')
    @php use App\Support\Money; @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('admin.invoices') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-neutral-600">{{ __('admin.invoices_export_intro') }}</p>
        </div>

        {{-- The month-end question, answered without a PDF at a time. --}}
        <form method="GET" action="/admin/invoices/export" class="flex flex-wrap items-end gap-2 text-sm">
            <div>
                <label for="from" class="block text-xs text-neutral-500">{{ __('admin.from') }}</label>
                <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" class="mt-1 rounded border border-neutral-300 px-2 py-1.5">
            </div>
            <div>
                <label for="to" class="block text-xs text-neutral-500">{{ __('admin.to') }}</label>
                <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" class="mt-1 rounded border border-neutral-300 px-2 py-1.5">
            </div>
            <button type="submit" class="rounded border border-neutral-300 px-4 py-2">{{ __('admin.export_csv') }}</button>
        </form>
    </div>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($invoices as $invoice)
            <li class="flex items-center justify-between gap-4 px-4 py-3">
                <div>
                    <a href="/admin/invoices/{{ $invoice->id }}.pdf" target="_blank"
                       class="font-mono font-medium hover:underline">{{ $invoice->number }}</a>
                    @if ($invoice->isCreditNote())
                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">{{ __('admin.credit_note') }}</span>
                    @endif
                    <p class="text-sm text-neutral-500">
                        {{ $invoice->issued_at->translatedFormat('j M Y') }} ·
                        {{ $invoice->booking?->reference }} ·
                        {{ $invoice->billed_to['name'] ?? '—' }}
                    </p>
                </div>
                <div class="text-right text-sm">
                    <strong>{{ Money::format($invoice->gross_total, $invoice->currency) }}</strong>
                    <span class="block text-neutral-500">
                        {{ __('invoice.tax_total') }} {{ Money::format($invoice->tax_total, $invoice->currency) }}
                    </span>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-neutral-500">{{ __('admin.no_invoices') }}</li>
        @endforelse
    </ul>

    <div class="mt-6">{{ $invoices->links() }}</div>
@endsection
