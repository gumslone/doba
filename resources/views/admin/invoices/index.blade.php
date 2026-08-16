@extends('admin.layout', ['title' => __('admin.invoices')])

@section('content')
    @php use App\Support\Money; @endphp

    <h1 class="mb-6 text-2xl font-semibold">{{ __('admin.invoices') }}</h1>

    <ul class="divide-y divide-neutral-200 rounded border border-neutral-200 bg-white">
        @forelse ($invoices as $invoice)
            <li class="flex items-center justify-between gap-4 px-4 py-3">
                <div>
                    <a href="/admin/invoices/{{ $invoice->id }}.pdf" target="_blank"
                       class="font-mono font-medium hover:underline">{{ $invoice->number }}</a>
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
