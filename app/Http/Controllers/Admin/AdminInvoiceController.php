<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Invoicing\InvoiceRenderer;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class AdminInvoiceController extends Controller
{
    public function index(): View
    {
        return view('admin.invoices.index', [
            'invoices' => Invoice::query()
                ->with('booking.guest')
                ->orderByDesc('year')
                ->orderByDesc('sequence')
                ->paginate(50),
        ]);
    }

    /**
     * Stream the PDF, rendering it on first request.
     *
     * Invoices are served through this authenticated route rather than
     * from the public disk: they carry the guest's name and address, and
     * sequential numbers make a public URL trivially enumerable.
     */
    public function download(Invoice $invoice, InvoiceRenderer $renderer): Response
    {
        if ($invoice->pdf_path === null || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            $renderer->store($invoice);
            $invoice->refresh();
        }

        return response(
            Storage::disk('local')->get($invoice->pdf_path),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
            ],
        );
    }
}
