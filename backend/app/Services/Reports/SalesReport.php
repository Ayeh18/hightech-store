<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the dashboard and the sales report.
 *
 * What "sales" means here (so nobody has to guess):
 *   - gross  = payments that arrived in the period (by the day they were paid), except money held for a refund
 *              (a payment that came too late for its order is not a sale);
 *   - refunds = refunds that were completed in the period (by the day they were refunded);
 *   - net    = gross - refunds. This is the "total sales" figure.
 * Days are the shop's days (Asia/Kuala_Lumpur by default), not UTC days: an order paid at 11 pm Malaysian time
 * belongs to that evening.
 */
class SalesReport
{
    public const MAX_DAYS = 366;

    private string $timezone;

    public function __construct()
    {
        $this->timezone = (string) config('shop.timezone');
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable} the period as UTC moments, for querying */
    public function bounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'start' => $from->setTimezone($this->timezone)->startOfDay()->utc(),
            'end' => $to->setTimezone($this->timezone)->endOfDay()->utc(),
        ];
    }

    /**
     * One entry per day in the period (days with no activity are included, as zeros), plus the totals.
     *
     * @return array{days: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        ['start' => $start, 'end' => $end] = $this->bounds($from, $to);
        $day = "(%s AT TIME ZONE 'UTC' AT TIME ZONE ?)::date";

        $paid = DB::table('payments')
            ->selectRaw(sprintf($day, 'paid_at').' as day, count(*) as payments, sum(amount) as gross', [$this->timezone])
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
            ->where('requires_refund', false)
            ->whereBetween('paid_at', [$start, $end])
            ->groupBy('day')->get()->keyBy('day');

        $refunded = DB::table('refunds')
            ->selectRaw(sprintf($day, 'refunded_at').' as day, sum(amount) as refunded', [$this->timezone])
            ->where('status', RefundStatus::Succeeded->value)
            ->whereBetween('refunded_at', [$start, $end])
            ->groupBy('day')->get()->keyBy('day');

        $placed = DB::table('orders')
            ->selectRaw(sprintf($day, 'created_at').' as day, count(*) as orders', [$this->timezone])
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('day')->get()->keyBy('day');

        $days = [];
        $totals = ['orders_placed' => 0, 'orders_paid' => 0, 'gross' => 0, 'refunded' => 0];

        for ($date = $from->setTimezone($this->timezone)->startOfDay(); $date->lte($to->setTimezone($this->timezone)); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = [
                'date' => $key,
                'orders_placed' => (int) ($placed[$key]->orders ?? 0),
                'orders_paid' => (int) ($paid[$key]->payments ?? 0),
                'gross' => (int) ($paid[$key]->gross ?? 0),
                'refunded' => (int) ($refunded[$key]->refunded ?? 0),
            ];
            $row['net'] = $row['gross'] - $row['refunded'];

            foreach ($totals as $name => $_) {
                $totals[$name] += $row[$name];
            }

            $days[] = [
                'date' => $key,
                'orders_placed' => $row['orders_placed'],
                'orders_paid' => $row['orders_paid'],
                'gross' => Money::fromSen($row['gross']),
                'refunded' => Money::fromSen($row['refunded']),
                'net' => Money::fromSen($row['net']),
            ];
        }

        return [
            'days' => $days,
            'totals' => [
                'orders_placed' => $totals['orders_placed'],
                'orders_paid' => $totals['orders_paid'],
                'gross' => Money::fromSen($totals['gross']),
                'refunded' => Money::fromSen($totals['refunded']),
                'net' => Money::fromSen($totals['gross'] - $totals['refunded']),
                // Average value of a paid order (whole sen, rounded down).
                'average_order' => Money::fromSen($totals['orders_paid'] > 0 ? intdiv($totals['gross'], $totals['orders_paid']) : 0),
            ],
        ];
    }

    /**
     * What was sold: items on orders paid in the period that were not cancelled or refunded afterwards.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(CarbonImmutable $from, CarbonImmutable $to, int $limit = 5): array
    {
        return $this->soldItems($from, $to)
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->selectRaw('p.id, p.name, p.sku, sum(i.quantity) as units, sum(i.line_total) as revenue')
            ->orderByDesc('revenue')->orderBy('p.id')
            ->limit($limit)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'sku' => (string) $r->sku, 'units' => (int) $r->units, 'revenue' => Money::fromSen((int) $r->revenue)])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function revenueByCategory(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->soldItems($from, $to)
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.id, c.name, sum(i.quantity) as units, sum(i.line_total) as revenue')
            ->orderByDesc('revenue')->orderBy('c.id')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'units' => (int) $r->units, 'revenue' => Money::fromSen((int) $r->revenue)])
            ->all();
    }

    private function soldItems(CarbonImmutable $from, CarbonImmutable $to)
    {
        ['start' => $start, 'end' => $end] = $this->bounds($from, $to);

        return DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereBetween('o.paid_at', [$start, $end])
            ->whereNotIn('o.status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value]);
    }
}
