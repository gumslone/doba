{{--
    The invoice document. Outside the theme system on purpose: a hotel's
    theme must not be able to break the layout of a tax document, and
    Dompdf supports only a subset of CSS anyway (no flex, no grid) — this
    is deliberately table-based markup from 2004.
--}}
@php
    use App\Support\Money;

    $money = fn (?int $minor): string => (string) Money::exact($minor, $invoice->currency, $locale);
    $percent = fn (int $bp): string => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 20mm 18mm 26mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #1c2321; line-height: 1.5; }
        h1 { font-size: 18pt; margin: 0 0 2mm; font-weight: normal; }
        .muted { color: #6b7370; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding: 0 0 7mm; }
        .lines { margin-top: 8mm; }
        .lines th {
            text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .08em;
            color: #6b7370; border-bottom: 1px solid #1c2321; padding: 0 0 2mm;
        }
        .lines td { padding: 2.5mm 0; border-bottom: 1px solid #e2ddd2; vertical-align: top; }
        .totals { margin-top: 6mm; width: 62mm; float: right; }
        .totals td { padding: 1.5mm 0; }
        .totals .grand td { border-top: 1px solid #1c2321; font-weight: bold; padding-top: 2.5mm; }
        .vat { margin-top: 20mm; clear: both; }
        .vat th, .vat td { font-size: 9pt; padding: 1.5mm 0; border-bottom: 1px solid #e2ddd2; text-align: right; }
        .vat th:first-child, .vat td:first-child { text-align: left; }
        /* Fixed, so it sits at the foot of EVERY page: a long stay can
           run the line table onto a second sheet, and each sheet of a
           tax document has to identify who issued it. */
        footer {
            position: fixed; bottom: -18mm; left: 0; right: 0;
            font-size: 8pt; color: #6b7370; border-top: 1px solid #e2ddd2; padding-top: 3mm;
        }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <strong>{{ $hotel->name }}</strong><br>
                @if ($street = $hotel->get('contact.street'))
                    {{ $street }}<br>
                    {{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }}<br>
                @endif
                @if ($country = $hotel->get('contact.country')){{ $country }}<br>@endif
                @if ($vatId = $hotel->get('tax.vat_id'))
                    <span class="muted">{{ __('invoice.vat_id', [], $locale) }}: {{ $vatId }}</span>
                @endif
            </td>
            <td class="right">
                <h1>{{ __('invoice.title', [], $locale) }}</h1>
                <span class="muted">{{ __('invoice.number', [], $locale) }}</span> <strong>{{ $invoice->number }}</strong><br>
                <span class="muted">{{ __('invoice.issued', [], $locale) }}</span> {{ $invoice->issued_at->translatedFormat('j F Y') }}<br>
                <span class="muted">{{ __('booking.reference', [], $locale) }}</span> {{ $invoice->booking?->reference }}
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="muted">{{ __('invoice.billed_to', [], $locale) }}</span><br>
                @php $billed = $invoice->billed_to ?? []; @endphp
                {{ $billed['name'] ?? '—' }}<br>
                @if (! empty($billed['address'])){{ $billed['address'] }}<br>@endif
                @if (! empty($billed['postal_code']) || ! empty($billed['city']))
                    {{ $billed['postal_code'] ?? '' }} {{ $billed['city'] ?? '' }}<br>
                @endif
                @if (! empty($billed['country'])){{ $billed['country'] }}@endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('invoice.description', [], $locale) }}</th>
                <th class="right">{{ __('invoice.quantity', [], $locale) }}</th>
                <th class="right">{{ __('invoice.net', [], $locale) }}</th>
                <th class="right">{{ __('invoice.vat', [], $locale) }}</th>
                <th class="right">{{ __('invoice.gross', [], $locale) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ $money($line->line_net) }}</td>
                    <td class="right">{{ $percent($line->tax_rate) }}</td>
                    <td class="right">{{ $money($line->line_gross) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">{{ __('invoice.net_total', [], $locale) }}</td>
            <td class="right">{{ $money($invoice->net_total) }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('invoice.tax_total', [], $locale) }}</td>
            <td class="right">{{ $money($invoice->tax_total) }}</td>
        </tr>
        <tr class="grand">
            <td>{{ __('invoice.gross_total', [], $locale) }}</td>
            <td class="right">{{ $money($invoice->gross_total) }}</td>
        </tr>
    </table>

    {{-- The per-rate breakdown a DE/PL/UA invoice is required to show: one
         row per VAT rate, because accommodation is reduced while breakfast,
         parking and spa are standard (§5). --}}
    <table class="vat">
        <thead>
            <tr>
                <th>{{ __('invoice.vat_breakdown', [], $locale) }}</th>
                <th>{{ __('invoice.net', [], $locale) }}</th>
                <th>{{ __('invoice.vat', [], $locale) }}</th>
                <th>{{ __('invoice.gross', [], $locale) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->taxBreakdown() as $band)
                <tr>
                    <td>{{ $percent($band['rate']) }}</td>
                    <td>{{ $money($band['net']) }}</td>
                    <td>{{ $money($band['tax']) }}</td>
                    <td>{{ $money($band['gross']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <footer>
        {{ __('invoice.footer', [], $locale) }}
        @if ($phone = $hotel->get('contact.phone')) · {{ $phone }} @endif
        @if ($email = $hotel->get('contact.email')) · {{ $email }} @endif
    </footer>
</body>
</html>
