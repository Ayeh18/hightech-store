<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Http\Resources\Admin\AdminOrderSummaryResource;
use App\Http\Resources\Admin\AdminPaymentResource;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Everything on the admin dashboard, in one answer. The payment tables are left out for people who may not view payments. */
class DashboardService
{
    public const TABLE_ROWS = 8;

    public function __construct(private readonly SalesReport $sales) {}

    /** @return array<string, mixed> */
    public function build(User $viewer, Request $request, int $days): array
    {
        $to = CarbonImmutable::now(config('shop.timezone'))->startOfDay();
        $from = $to->subDays($days - 1);
        $daily = $this->sales->daily($from, $to);
        $canSeePayments = $viewer->can('payments.view');

        return [
            'period' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'cards' => $this->cards($daily['totals'], $from, $to),
            'sales_by_day' => $daily['days'],
            'top_products' => $this->sales->topProducts($from, $to, 5),
            'revenue_by_category' => $this->sales->revenueByCategory($from, $to),
            'recent_orders' => AdminOrderSummaryResource::collection(
                Order::with('user')->withCount('items')->latest()->latest('id')->limit(self::TABLE_ROWS)->get(),
            )->resolve($request),
            'low_stock_products' => $this->lowStock(self::TABLE_ROWS),
            'recent_payments' => $canSeePayments ? $this->payments($request, null) : null,
            'failed_payments' => $canSeePayments ? $this->payments($request, PaymentStatus::Failed) : null,
        ];
    }

    /** @param  array<string, mixed>  $totals  from SalesReport::daily() */
    private function cards(array $totals, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pending = Order::whereIn('status', [OrderStatus::PendingPayment->value, OrderStatus::PaymentProcessing->value]);
        ['start' => $start, 'end' => $end] = $this->sales->bounds($from, $to);
        $customers = User::whereHas('roles', fn ($q) => $q->where('slug', 'customer'));

        return [
            'total_sales' => $totals['net'],
            'gross_sales' => $totals['gross'],
            'refunded' => $totals['refunded'],
            'orders' => $totals['orders_placed'],
            'orders_paid' => $totals['orders_paid'],
            'average_order' => $totals['average_order'],
            'pending_payments' => ['count' => (clone $pending)->count(), 'amount' => Money::fromSen((int) (clone $pending)->sum('total'))],
            'products' => ['active' => Product::where('status', ProductStatus::Active->value)->count(), 'total' => Product::count()],
            'low_stock' => $this->lowStockQuery()->count(),
            'customers' => ['total' => (clone $customers)->count(), 'new' => (clone $customers)->whereBetween('created_at', [$start, $end])->count()],
        ];
    }

    /** Active products that cannot be sold much longer: available units at or below their own threshold (out of stock included). */
    private function lowStockQuery()
    {
        return Inventory::query()
            ->join('products as p', 'p.id', '=', 'inventories.product_id')
            ->whereNull('p.deleted_at')
            ->where('p.status', ProductStatus::Active->value)
            ->whereRaw('(inventories.stock - inventories.reserved) <= inventories.low_stock_threshold');
    }

    /** @return array<int, array<string, mixed>> */
    private function lowStock(int $limit): array
    {
        return $this->lowStockQuery()
            ->selectRaw('p.id, p.name, p.sku, inventories.stock, inventories.reserved, (inventories.stock - inventories.reserved) as available, inventories.low_stock_threshold')
            ->orderByRaw('(inventories.stock - inventories.reserved) asc')->orderBy('p.id')
            ->limit($limit)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'sku' => $r->sku, 'stock' => (int) $r->stock, 'reserved' => (int) $r->reserved,
                'available' => (int) $r->available, 'low_stock_threshold' => (int) $r->low_stock_threshold,
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function payments(Request $request, ?PaymentStatus $status): array
    {
        $payments = Payment::with('order.user')
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->latest()->latest('id')->limit(self::TABLE_ROWS)->get();

        return AdminPaymentResource::collection($payments)->resolve($request);
    }
}
