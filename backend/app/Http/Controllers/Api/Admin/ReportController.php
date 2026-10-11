<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\Insights;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for any date range: sales (on screen and as a CSV file) and insights. The routes require the reports.view
 * permission.
 */
class ReportController extends Controller
{
    public function __construct(private readonly SalesReport $sales) {}

    /**
     * Sales report for a period.
     *
     * Sales for a period: totals per day and the best-selling products.
     */
    #[QueryParameter('from', description: 'The first day of the period, as the shop counts days (Malaysian time).', required: true, type: 'string', format: 'date', example: '2026-10-01')]
    #[QueryParameter('to', description: 'The last day of the period, included. At most a year after `from`.', required: true, type: 'string', format: 'date', example: '2026-10-31')]
    #[ApiResponse(422, 'The dates are missing, not dates, in the wrong order, or more than a year apart.', type: 'array{message: string, errors?: array<string, string[]>}')]
    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ...$this->sales->daily($from, $to),
            'products' => $this->sales->topProducts($from, $to, 50),
            'revenue_by_category' => $this->sales->revenueByCategory($from, $to),
        ]]);
    }

    /**
     * Download the sales report as CSV.
     *
     * One row per day. Amounts are in ringgit with two decimals, ready for a spreadsheet.
     */
    #[QueryParameter('from', description: 'The first day of the period, as the shop counts days (Malaysian time).', required: true, type: 'string', format: 'date', example: '2026-10-01')]
    #[QueryParameter('to', description: 'The last day of the period, included. At most a year after `from`.', required: true, type: 'string', format: 'date', example: '2026-10-31')]
    #[ApiResponse(422, 'The dates are missing, not dates, in the wrong order, or more than a year apart.', type: 'array{message: string, errors?: array<string, string[]>}')]
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $report = $this->sales->daily($from, $to);
        $ringgit = fn ($money) => sprintf('%s%d.%02d', $money->sen < 0 ? '-' : '', intdiv(abs($money->sen), 100), abs($money->sen) % 100);

        return response()->streamDownload(function () use ($report, $ringgit) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Orders placed', 'Orders paid', 'Gross sales (RM)', 'Refunded (RM)', 'Net sales (RM)']);

            foreach ($report['days'] as $day) {
                fputcsv($out, [$day['date'], $day['orders_placed'], $day['orders_paid'], $ringgit($day['gross']), $ringgit($day['refunded']), $ringgit($day['net'])]);
            }

            $t = $report['totals'];
            fputcsv($out, ['Total', $t['orders_placed'], $t['orders_paid'], $ringgit($t['gross']), $ringgit($t['refunded']), $ringgit($t['net'])]);
            fclose($out);
        }, "sales-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Insights for a period.
     *
     * Who is buying, where orders are lost, how payments go, how much is sold as whole PCs, and how the build
     * suggestions are used. Counts and sums only: no customer is named. Percentages are numbers from 0 to 100 with at
     * most one decimal place, or `null` when there is nothing to take a percentage of.
     */
    #[QueryParameter('from', description: 'The first day of the period, as the shop counts days (Malaysian time).', required: true, type: 'string', format: 'date', example: '2026-10-01')]
    #[QueryParameter('to', description: 'The last day of the period, included. At most a year after `from`.', required: true, type: 'string', format: 'date', example: '2026-10-31')]
    #[ApiResponse(422, 'The dates are missing, not dates, in the wrong order, or more than a year apart.', type: 'array{message: string, errors?: array<string, string[]>}')]
    public function insights(Request $request, Insights $insights): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ...$insights->build($from, $to),
        ]]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = config('shop.timezone');
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay();

        abort_if($from->diffInDays($to) >= SalesReport::MAX_DAYS, 422, 'Choose a period of at most '.SalesReport::MAX_DAYS.' days.');

        return [$from, $to];
    }
}
