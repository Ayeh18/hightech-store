<?php

namespace App\Services\Reports;

use App\Enums\BuilderSlot;
use App\Enums\BuildPurpose;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecommenderFailure;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The questions the sales report does not answer: who is buying, where orders are lost, how payments go, how much is
 * sold as whole PCs, and how "Suggest a build for me" is used.
 *
 * Everything here is a count or a sum over a period, so no customer is named. What each number means is written next
 * to it (and shown in the API reference), so nobody has to guess. The period is in the shop's own days, exactly as in
 * the sales report (SalesReport::bounds).
 */
class Insights
{
    /** How long the "most common" lists are. */
    public const TOP = 5;

    /** How many products are listed for each slot of a PC. */
    public const PARTS_PER_SLOT = 3;

    /** Where one budget band ends and the next begins, in sen: under RM 3,000, under RM 5,000, under RM 8,000, the rest. */
    public const BUDGET_BANDS = [300_000, 500_000, 800_000];

    /** An order in one of these states is not a sale, even if it was paid for at some point. */
    private const NOT_SOLD = [OrderStatus::Cancelled->value, OrderStatus::Refunded->value];

    public function __construct(private readonly SalesReport $sales) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        ['start' => $start, 'end' => $end] = $this->sales->bounds($from, $to);

        return [
            'customers' => $this->customers($start, $end),
            'checkout' => $this->checkout($start, $end),
            'payments' => $this->payments($start, $end),
            'pcs' => $this->pcs($from, $to),
            'suggestions' => $this->suggestions($start, $end),
        ];
    }

    /**
     * Who bought: the people behind the orders paid in the period (orders cancelled or refunded afterwards left out).
     *
     * @return array<string, mixed>
     */
    private function customers(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = DB::table('orders as o')
            ->whereBetween('o.paid_at', [$start, $end])
            ->whereNotIn('o.status', self::NOT_SOLD)
            ->selectRaw('count(*) as orders, count(distinct o.user_id) as buyers')
            ->selectRaw(
                'count(distinct o.user_id) filter (where exists (select 1 from orders e where e.user_id = o.user_id and e.paid_at < ? and e.status not in (?, ?))) as came_back',
                [$start, ...self::NOT_SOLD],
            )
            ->first();

        $buyers = (int) $row->buyers;
        $returning = (int) $row->came_back;

        return [
            // People who paid for at least one order in the period.
            'buyers' => $buyers,
            // Buyers whose first paid order was in this period.
            'new' => $buyers - $returning,
            // Buyers who had already paid for an order before the period started.
            'returning' => $returning,
            // Returning buyers as a percentage of all buyers. Null when nobody bought.
            'returning_rate' => $this->rate($returning, $buyers),
            // Orders paid in the period.
            'orders' => (int) $row->orders,
            // Null when nobody bought.
            'orders_per_buyer' => $buyers > 0 ? round((int) $row->orders / $buyers, 2) : null,
        ];
    }

    /**
     * What became of the orders PLACED in the period: paid, still waiting, or given up.
     *
     * @return array<string, mixed>
     */
    private function checkout(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('count(*) as placed, count(paid_at) as paid')
            ->selectRaw('count(*) filter (where paid_at is null and status in (?, ?)) as awaiting', [OrderStatus::PendingPayment->value, OrderStatus::PaymentProcessing->value])
            ->selectRaw('count(*) filter (where paid_at is null and status = ?) as cancelled', [OrderStatus::Cancelled->value])
            ->selectRaw('count(*) filter (where paid_at is not null and coupon_code is not null) as with_coupon')
            ->selectRaw('coalesce(sum(discount_total) filter (where paid_at is not null), 0) as discount')
            ->first();

        return [
            // Orders created in the period.
            'placed' => (int) $row->placed,
            // Of those, the ones the customer paid for (counted even if the order was refunded later).
            'paid' => (int) $row->paid,
            // Of those, the ones still waiting for their payment.
            'awaiting_payment' => (int) $row->awaiting,
            // Of those, the ones cancelled without being paid: by the customer, by staff, or because time ran out.
            'cancelled_unpaid' => (int) $row->cancelled,
            // Paid orders as a percentage of placed orders. Null when none were placed.
            'paid_rate' => $this->rate((int) $row->paid, (int) $row->placed),
            // Paid orders that used a coupon.
            'with_coupon' => (int) $row->with_coupon,
            // What coupons took off the paid orders.
            'discount_given' => Money::fromSen((int) $row->discount),
        ];
    }

    /**
     * How the payment ATTEMPTS started in the period ended. One order can have several: a failed one, then a retry.
     *
     * @return array<string, mixed>
     */
    private function payments(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $attempts = fn () => DB::table('payments')->whereBetween('created_at', [$start, $end]);
        // A refunded payment did succeed first.
        $succeeded = [PaymentStatus::Paid->value, PaymentStatus::Refunded->value];

        $row = $attempts()
            ->selectRaw('count(*) as attempts')
            ->selectRaw('count(*) filter (where status in (?, ?)) as paid', $succeeded)
            ->selectRaw('count(*) filter (where status = ?) as failed', [PaymentStatus::Failed->value])
            ->selectRaw('count(*) filter (where status = ?) as cancelled', [PaymentStatus::Cancelled->value])
            ->first();

        $paid = (int) $row->paid;
        $failed = (int) $row->failed;
        $cancelled = (int) $row->cancelled;

        return [
            'attempts' => (int) $row->attempts,
            'paid' => $paid,
            'failed' => $failed,
            // The customer left the payment page, or the order was cancelled first.
            'cancelled' => $cancelled,
            // Not finished yet.
            'open' => (int) $row->attempts - $paid - $failed - $cancelled,
            // Paid attempts as a percentage of the finished ones. Null when none has finished.
            'success_rate' => $this->rate($paid, $paid + $failed + $cancelled),
            // Why attempts failed, most common first, in the payment company's words.
            'failure_reasons' => $attempts()
                ->where('status', PaymentStatus::Failed->value)
                ->selectRaw("coalesce(nullif(failure_reason, ''), 'No reason given') as reason, count(*) as attempts")
                ->groupBy('reason')->orderByDesc('attempts')->orderBy('reason')
                ->limit(self::TOP)->get()
                ->map(fn ($r) => ['reason' => (string) $r->reason, 'attempts' => (int) $r->attempts])
                ->all(),
            // How people paid (the paid attempts only), most money first.
            'methods' => $attempts()
                ->whereIn('status', $succeeded)
                ->selectRaw("coalesce(nullif(method, ''), 'unknown') as method, count(*) as payments, sum(amount) as amount")
                ->groupBy('method')->orderByDesc('amount')->orderBy('method')
                ->get()
                ->map(fn ($r) => ['method' => (string) $r->method, 'payments' => (int) $r->payments, 'amount' => Money::fromSen((int) $r->amount)])
                ->all(),
        ];
    }

    /**
     * Whole PCs against single items, among what was sold in the period (the same items as the sales report's
     * "what sold"). Amounts are the items' own prices, before coupons and shipping.
     *
     * @return array<string, mixed>
     */
    private function pcs(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = fn () => $this->sales->soldItems($from, $to);
        $parts = fn () => $items()->whereNotNull('i.build_group');

        $split = $items()
            ->selectRaw('coalesce(sum(i.line_total) filter (where i.build_group is not null), 0) as pc_revenue')
            ->selectRaw('coalesce(sum(i.line_total) filter (where i.build_group is null), 0) as single_revenue')
            ->selectRaw('coalesce(sum(i.quantity) filter (where i.build_group is null), 0) as single_units')
            ->first();

        // The parts of one PC share a build_group within their order, and each carries how many of that PC were ordered.
        $sold = (int) DB::query()
            ->fromSub($parts()->groupBy('i.order_id', 'i.build_group')->selectRaw('max(i.build_copies) as copies'), 'pc')
            ->sum('copies');

        $pcRevenue = (int) $split->pc_revenue;
        $singleRevenue = (int) $split->single_revenue;

        return [
            // PCs sold from the builder. Two of the same build in one order count as two.
            'sold' => $sold,
            'revenue' => Money::fromSen($pcRevenue),
            // Whole sen, rounded down.
            'average_price' => Money::fromSen($sold > 0 ? intdiv($pcRevenue, $sold) : 0),
            // Everything sold on its own, not as part of a PC.
            'single_items' => ['units' => (int) $split->single_units, 'revenue' => Money::fromSen($singleRevenue)],
            // The PCs' percentage of everything sold, by value. Null when nothing was sold.
            'revenue_share' => $this->rate($pcRevenue, $pcRevenue + $singleRevenue),
            'popular_parts' => $this->popularParts($parts(), $sold),
            'pairings' => $this->pairings($parts()),
        ];
    }

    /**
     * For each slot of a PC, the products chosen most often.
     *
     * @param  Builder  $parts  the sold order items that belong to a PC, as "i"
     * @return array<int, array<string, mixed>>
     */
    private function popularParts(Builder $parts, int $sold): array
    {
        $ranked = $parts
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->groupBy('i.slot', 'p.id', 'p.name')
            ->selectRaw('i.slot, p.id, p.name, sum(i.build_copies) as pcs')
            ->selectRaw('row_number() over (partition by i.slot order by sum(i.build_copies) desc, p.id) as place');

        $rows = DB::query()->fromSub($ranked, 'ranked')->where('place', '<=', self::PARTS_PER_SLOT)->get();
        $order = array_flip(array_map(fn (BuilderSlot $slot) => $slot->value, BuilderSlot::cases()));

        return $rows
            ->filter(fn ($r) => isset($order[$r->slot]))
            ->sortBy(fn ($r) => [$order[$r->slot], (int) $r->place])
            ->values()
            ->map(fn ($r) => [
                'slot' => (string) $r->slot,
                'slot_label' => BuilderSlot::from($r->slot)->label(),
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                // How many of the PCs sold had this product.
                'pcs' => (int) $r->pcs,
                // As a percentage of the PCs sold.
                'share' => $this->rate((int) $r->pcs, $sold),
            ])
            ->all();
    }

    /**
     * The processor and graphics card combinations sold most often. A PC that runs on the processor's built-in
     * graphics has no graphics card: `gpu` is null.
     *
     * @param  Builder  $parts  the sold order items that belong to a PC, as "i"
     * @return array<int, array<string, mixed>>
     */
    private function pairings(Builder $parts): array
    {
        return $parts
            ->where('i.slot', BuilderSlot::Cpu->value)
            ->leftJoin('order_items as g', fn ($join) => $join
                ->on('g.order_id', '=', 'i.order_id')
                ->on('g.build_group', '=', 'i.build_group')
                ->where('g.slot', BuilderSlot::Gpu->value))
            ->join('products as cpu', 'cpu.id', '=', 'i.product_id')
            ->leftJoin('products as gpu', 'gpu.id', '=', 'g.product_id')
            ->groupBy('cpu.id', 'cpu.name', 'gpu.id', 'gpu.name')
            ->selectRaw('cpu.name as cpu, gpu.name as gpu, sum(i.build_copies) as pcs')
            ->orderByDesc('pcs')->orderBy('cpu.id')->orderBy('gpu.id')
            ->limit(self::TOP)->get()
            ->map(fn ($r) => ['cpu' => (string) $r->cpu, 'gpu' => $r->gpu === null ? null : (string) $r->gpu, 'pcs' => (int) $r->pcs])
            ->all();
    }

    /**
     * How "Suggest a build for me" was used: the suggestions made in the period.
     *
     * @return array<string, mixed>
     */
    private function suggestions(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $made = fn () => DB::table('build_recommendations')->whereBetween('created_at', [$start, $end]);
        [$low, $middle, $high] = self::BUDGET_BANDS;

        $row = $made()
            ->selectRaw('count(*) as asked, count(accepted_at) as accepted, coalesce(avg(budget), 0) as average_budget')
            ->selectRaw("count(*) filter (where source = 'ai') as ai_used, count(fallback_reason) as ai_fell_back")
            ->selectRaw('count(*) filter (where budget < ?) as band_1', [$low])
            ->selectRaw('count(*) filter (where budget >= ? and budget < ?) as band_2', [$low, $middle])
            ->selectRaw('count(*) filter (where budget >= ? and budget < ?) as band_3', [$middle, $high])
            ->selectRaw('count(*) filter (where budget >= ?) as band_4', [$high])
            ->first();

        $byPurpose = $made()->selectRaw('purpose, count(*) as asked, count(accepted_at) as accepted')->groupBy('purpose')->get()->keyBy('purpose');
        $ringgit = fn (int $sen) => 'RM '.number_format(intdiv($sen, 100));

        return [
            // Builds suggested.
            'asked' => (int) $row->asked,
            // Of those, the ones the customer loaded into the builder.
            'accepted' => (int) $row->accepted,
            // Accepted suggestions as a percentage of all suggestions. Null when none was made.
            'acceptance_rate' => $this->rate((int) $row->accepted, (int) $row->asked),
            // Whole sen. Zero when no suggestion was made.
            'average_budget' => Money::fromSen((int) round((float) $row->average_budget)),
            /**
             * Every purpose a customer can choose, in the order of the form, including those nobody chose.
             *
             * @var list<array{purpose: string, label: string, asked: int, accepted: int}>
             */
            'by_purpose' => array_map(fn (BuildPurpose $purpose) => [
                'purpose' => $purpose->value,
                'label' => $purpose->label(),
                'asked' => (int) ($byPurpose[$purpose->value]->asked ?? 0),
                'accepted' => (int) ($byPurpose[$purpose->value]->accepted ?? 0),
            ], BuildPurpose::cases()),
            // What customers said they want to spend.
            'by_budget' => [
                ['label' => 'Under '.$ringgit($low), 'asked' => (int) $row->band_1],
                ['label' => $ringgit($low).' to under '.$ringgit($middle), 'asked' => (int) $row->band_2],
                ['label' => $ringgit($middle).' to under '.$ringgit($high), 'asked' => (int) $row->band_3],
                ['label' => $ringgit($high).' and above', 'asked' => (int) $row->band_4],
            ],
            // Only when the shop is set up to ask an AI first (RECOMMENDER_DRIVER=ai). Otherwise all zeros.
            'ai' => [
                // Suggestions for which the AI was asked.
                'asked' => (int) $row->ai_used + (int) $row->ai_fell_back,
                // The AI's build passed the shop's checks and was shown.
                'used' => (int) $row->ai_used,
                // The AI's build was not usable, so the shop's own rules chose instead.
                'fell_back' => (int) $row->ai_fell_back,
                // Why, most common first. `kind` is a short code such as `service_error` or `over_budget`; `label` says it in words.
                'reasons' => $made()
                    ->whereNotNull('fallback_reason')
                    ->selectRaw('coalesce(fallback_kind, ?) as kind, count(*) as times', [RecommenderFailure::Other->value])
                    ->groupBy('kind')->orderByDesc('times')->orderBy('kind')
                    ->get()
                    ->map(fn ($r) => [
                        'kind' => (string) $r->kind,
                        'label' => (RecommenderFailure::tryFrom((string) $r->kind) ?? RecommenderFailure::Other)->label(),
                        'times' => (int) $r->times,
                    ])
                    ->all(),
            ],
        ];
    }

    /** `$part` as a percentage of `$whole`, to one decimal place. Null when there is nothing to take a percentage of. */
    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
