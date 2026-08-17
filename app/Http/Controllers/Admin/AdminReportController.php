<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\Reports;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    public function index(Request $request, Reports $reports): View
    {
        [$from, $to] = $this->range($request);

        return view('admin.reports.index', [
            'from' => $from,
            'to' => $to,
            'summary' => $reports->summary($from, $to),
            'months' => $reports->byMonth($from, $to),
            'mix' => $reports->channelMix($from, $to),
            'pace' => $reports->pace($from, $to),
        ]);
    }

    /**
     * The same figures as a spreadsheet.
     *
     * Every hotelier's accountant wants a column of numbers, and a report
     * that can only be read on screen gets retyped by hand — which is
     * where the transcription errors come from.
     */
    public function export(Request $request, Reports $reports): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $months = $reports->byMonth($from, $to);

        return response()->streamDownload(
            static function () use ($months): void {
                $out = fopen('php://output', 'wb');

                fputcsv($out, ['month', 'capacity', 'room_nights_sold', 'ota_nights', 'occupancy', 'adr', 'revpar', 'room_revenue']);

                foreach ($months as $month) {
                    fputcsv($out, [
                        $month['month']->format('Y-m'),
                        $month['capacity'],
                        $month['room_nights_sold'],
                        $month['ota_nights'],
                        round($month['occupancy'] * 100, 1),
                        // Minor units throughout, as they are stored (§5):
                        // a spreadsheet that silently divides by 100 is a
                        // spreadsheet that rounds somebody's year.
                        $month['adr'],
                        $month['revpar'],
                        $month['room_revenue'],
                    ]);
                }

                fclose($out);
            },
            sprintf('doba-%s-to-%s.csv', $from->toDateString(), $to->toDateString()),
            ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'private, no-store'],
        );
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    protected function range(Request $request): array
    {
        $default = CarbonImmutable::today();

        $from = $this->date($request->query('from')) ?? $default->startOfYear();
        $to = $this->date($request->query('to')) ?? $default->endOfMonth();

        // An inverted range is a typo, not a request for negative nights.
        return $from->lte($to) ? [$from, $to] : [$to, $from];
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::parse($value)->startOfDay()
            : null;
    }
}
