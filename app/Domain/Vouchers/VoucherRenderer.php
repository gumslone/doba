<?php

declare(strict_types=1);

namespace App\Domain\Vouchers;

use App\Models\GiftVoucher;
use App\Support\Hotel\HotelSettings;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The voucher as something to put in an envelope.
 *
 * Same constraints as the invoice: nothing remote, a font that carries
 * Cyrillic, table markup Dompdf can lay out.
 */
class VoucherRenderer
{
    public function __construct(protected HotelSettings $hotel) {}

    public function render(GiftVoucher $voucher): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('vouchers.document', [
            'voucher' => $voucher,
            'hotel' => $this->hotel,
            'locale' => $voucher->locale,
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A5', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
