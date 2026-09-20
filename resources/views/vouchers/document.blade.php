{{--
    The printed voucher. Outside the theme system, like the invoice: a
    theme must not be able to break something a guest has paid for.
--}}
@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { font-family: "DejaVu Sans", sans-serif; color: #1c2321; margin: 0; }
        .frame { margin: 9mm; border: 0.6mm solid #a9791c; padding: 9mm 12mm; height: 112mm; }
        .hotel { font-size: 11pt; letter-spacing: .18em; text-transform: uppercase; color: #6b7370; }
        h1 { font-family: "DejaVu Serif", serif; font-weight: normal; font-size: 30pt; margin: 3mm 0 0; }
        .value { font-family: "DejaVu Serif", serif; font-size: 40pt; color: #2f5d4a; margin: 4mm 0 2mm; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .label { font-size: 7.5pt; letter-spacing: .14em; text-transform: uppercase; color: #6b7370; }
        .who { font-size: 13pt; margin-bottom: 3mm; }
        .msg { font-size: 10.5pt; font-style: italic; color: #3b4340; margin-top: 2mm; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-size: 17pt; letter-spacing: .08em; }
        .foot { font-size: 8pt; color: #6b7370; margin-top: 5mm; border-top: 0.2mm solid #d8d2c4; padding-top: 3mm; }
    </style>
</head>
<body>
<div class="frame">
    <div class="hotel">{{ $hotel->name }}</div>
    <h1>{{ __('vouchers.one', [], $locale) }}</h1>
    <div class="value">{{ Money::exact($voucher->initial_amount, $voucher->currency, $locale) }}</div>

    <table>
        <tr>
            <td style="width:58%">
                @if ($voucher->recipient_name)
                    <div class="label">{{ __('vouchers.for', [], $locale) }}</div>
                    <div class="who">{{ $voucher->recipient_name }}</div>
                @endif
                <div class="label">{{ __('vouchers.from', [], $locale) }}</div>
                <div class="who">{{ $voucher->buyer_name }}</div>
                @if ($voucher->message)
                    <div class="msg">{{ $voucher->message }}</div>
                @endif
            </td>
            <td style="width:42%; text-align:right">
                <div class="label">{{ __('vouchers.code', [], $locale) }}</div>
                <div class="code">{{ $voucher->code }}</div>
                @if ($voucher->expires_on)
                    <div class="label" style="margin-top:4mm">{{ __('vouchers.valid_until', [], $locale) }}</div>
                    <div>{{ $voucher->expires_on->translatedFormat('j F Y') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="foot">
        {{ __('vouchers.pdf_redeem', ['url' => preg_replace('#^https?://#', '', rtrim((string) config('app.url'), '/'))], $locale) }}
        @if ($hotel->get('contact.street')) · {{ $hotel->get('contact.street') }}, {{ $hotel->get('contact.postal_code') }} {{ $hotel->get('contact.city') }} @endif
        @if ($hotel->get('contact.phone')) · {{ $hotel->get('contact.phone') }} @endif
    </div>
</div>
</body>
</html>
