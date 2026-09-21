{{-- The registration form. Table markup, because Dompdf. --}}
@php use App\Support\Countries; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1c2321; line-height: 1.45; }
        h1 { font-size: 17pt; font-weight: normal; margin: 0 0 1mm; }
        .muted { color: #6b7370; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 0 0 6mm; vertical-align: top; }
        .person { margin-top: 5mm; border: 0.3mm solid #d8d2c4; }
        .person th { text-align: left; background: #f4f1ea; padding: 2mm 3mm; font-size: 8.5pt; letter-spacing: .08em; text-transform: uppercase; }
        .person td { padding: 1.6mm 3mm; border-top: 0.2mm solid #e8e3d8; vertical-align: top; }
        .person td.k { width: 34%; color: #6b7370; }
        .sign { margin-top: 16mm; }
        .sign td { width: 50%; padding-right: 10mm; }
        .line { border-bottom: 0.3mm solid #1c2321; height: 12mm; }
    </style>
</head>
<body>
    <table class="meta"><tr>
        <td>
            <h1>{{ __('checkin.form_title', [], $locale) }}</h1>
            <div class="muted">{{ $hotel->name }}@if ($hotel->get('contact.street')) · {{ $hotel->get('contact.street') }}, {{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }}@endif</div>
        </td>
        <td style="text-align:right">
            <strong>{{ $booking->reference }}</strong><br>
            {{ $booking->check_in->toDateString() }} – {{ $booking->check_out->toDateString() }}<br>
            <span class="muted">{{ $booking->rooms->map(fn ($r) => $r->room?->number ?? $r->roomType?->t('name', $locale))->filter()->join(', ') }}</span>
        </td>
    </tr></table>

    @foreach ($registration->party as $i => $person)
        <table class="person">
            <tr><th colspan="2">{{ $i === 0 ? ($person['first_name'].' '.$person['last_name']) : __('checkin.guest_n', ['n' => $i + 1], $locale).' · '.$person['first_name'].' '.$person['last_name'] }}</th></tr>
            <tr><td class="k">{{ __('checkin.date_of_birth', [], $locale) }}</td><td>{{ $person['date_of_birth'] ?? '—' }}</td></tr>
            <tr><td class="k">{{ __('checkin.nationality', [], $locale) }}</td><td>{{ Countries::name($person['nationality'] ?? null, $locale) ?? '—' }}</td></tr>
            <tr><td class="k">{{ __('checkin.street', [], $locale) }}</td><td>{{ $person['street'] ?? '—' }}</td></tr>
            <tr><td class="k">{{ __('checkin.city', [], $locale) }}</td><td>{{ trim(($person['postal_code'] ?? '').' '.($person['city'] ?? '')) ?: '—' }}, {{ Countries::name($person['country'] ?? null, $locale) ?? '' }}</td></tr>
            @if (! empty($person['document_number']))
                <tr><td class="k">{{ __('checkin.document_type', [], $locale) }}</td><td>{{ __('checkin.doc_'.($person['document_type'] ?: 'other'), [], $locale) }} · {{ $person['document_number'] }}</td></tr>
            @endif
        </table>
    @endforeach

    <table class="sign"><tr>
        <td><div class="line"></div><div class="muted">{{ __('checkin.place_date', [], $locale) }}</div></td>
        <td><div class="line"></div><div class="muted">{{ __('checkin.signature', [], $locale) }}</div></td>
    </tr></table>
</body>
</html>
