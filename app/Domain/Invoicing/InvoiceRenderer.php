<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use App\Support\Hotel\HotelSettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an invoice to PDF with Dompdf (§8).
 *
 * Deliberately NOT spatie/laravel-pdf: that renders via Browsershot, which
 * needs headless Chromium and Node on the machine generating the PDF.
 * Across twenty installs that is twenty Chromium footprints, a new class
 * of memory and zombie-process incident, and a contradiction of §2's
 * "Node is build-time only".
 */
class InvoiceRenderer
{
    public function __construct(protected HotelSettings $hotel) {}

    /**
     * Render and store the PDF, returning its path on the local disk.
     *
     * Invoices live on the private disk, never the public one: they carry
     * the guest's name and address, and a guessable public URL would leak
     * every guest's details to anyone who can count.
     */
    public function store(Invoice $invoice): string
    {
        $path = sprintf('invoices/%d/%s.pdf', $invoice->year, $invoice->number);

        Storage::disk('local')->put($path, $this->render($invoice));

        $invoice->update(['pdf_path' => $path]);

        return $path;
    }

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'booking.guest']);

        $options = new Options;
        // No remote anything: an invoice must render identically offline
        // and must never fetch a URL that ends up in its layout.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');   // ships with Dompdf; has Cyrillic for uk/ru

        $dompdf = new Dompdf($options);

        $dompdf->loadHtml(view('invoices.document', [
            'invoice' => $invoice,
            'hotel' => $this->hotel,
            'locale' => $invoice->booking->locale,
        ])->render(), 'UTF-8');

        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
