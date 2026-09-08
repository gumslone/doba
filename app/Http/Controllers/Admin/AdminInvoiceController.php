<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Invoicing\InvoiceRenderer;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices and credit notes (§5): the list, the PDF, and the CSV the
 * accountant asks for at the end of the month.
 */
class AdminInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('admin.invoices.index', [
            'invoices' => Invoice::query()
                ->with('booking.guest')
                ->orderByDesc('year')
                ->orderByDesc('sequence')
                ->paginate(50),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * One row per document for the period, with the VAT split by rate.
     *
     * Shaped for the person who books it, not for the machine that made
     * it: decimal amounts with a dot, a column pair per VAT rate found in
     * the period so a 7% and a 19% net can go to different accounts, a
     * credit note as a negative row under its own kind, and a UTF-8 BOM
     * so Excel reads the umlauts in the guest names.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        $invoices = Invoice::query()
            ->with(['lines', 'booking', 'creditsInvoice'])
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->orderBy('year')
            ->orderBy('sequence')
            ->get();

        /** @var array<int,int> $rates */
        $rates = $invoices
            ->flatMap(static fn (Invoice $invoice) => $invoice->lines->pluck('tax_rate'))
            ->map(static fn ($rate): int => (int) $rate)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return response()->streamDownload(
            static function () use ($invoices, $rates): void {
                $out = fopen('php://output', 'wb');

                fwrite($out, "\xEF\xBB\xBF");

                $header = ['number', 'kind', 'issued_on', 'booking', 'billed_to', 'country', 'currency', 'net', 'tax', 'gross', 'credits'];

                foreach ($rates as $rate) {
                    $label = rtrim(rtrim(number_format($rate / 100, 2, '.', ''), '0'), '.');
                    $header[] = 'net_'.$label;
                    $header[] = 'tax_'.$label;
                }

                fputcsv($out, $header);

                foreach ($invoices as $invoice) {
                    $byRate = $invoice->taxBreakdown()->keyBy('rate');

                    $row = [
                        $invoice->number,
                        $invoice->kind,
                        $invoice->issued_at->toDateString(),
                        $invoice->booking?->reference,
                        $invoice->billed_to['name'] ?? '',
                        $invoice->billed_to['country'] ?? '',
                        $invoice->currency,
                        Money::decimal($invoice->net_total),
                        Money::decimal($invoice->tax_total),
                        Money::decimal($invoice->gross_total),
                        $invoice->creditsInvoice?->number ?: '',
                    ];

                    foreach ($rates as $rate) {
                        $band = $byRate->get($rate);
                        $row[] = Money::decimal($band['net'] ?? 0);
                        $row[] = Money::decimal($band['tax'] ?? 0);
                    }

                    fputcsv($out, $row);
                }

                fclose($out);
            },
            sprintf('doba-invoices-%s-to-%s.csv', $from->toDateString(), $to->toDateString()),
            ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store'],
        );
    }

    /**
     * The period asked for, defaulting to the current month so far —
     * the question is nearly always "this month" or "last month".
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    protected function range(Request $request): array
    {
        $today = CarbonImmutable::today(config('doba.timezone'));

        $from = $request->filled('from') ? CarbonImmutable::parse((string) $request->query('from'))->startOfDay() : $today->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->query('to'))->startOfDay() : $today;

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
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
