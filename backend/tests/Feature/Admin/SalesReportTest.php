<?php

use App\Models\Product;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
});

describe('the sales report', function () {
    it('is for staff and admins only', function () {
        $query = '?from=2026-10-01&to=2026-10-20';

        $this->getJson('/api/admin/reports/sales'.$query)->assertUnauthorized();
        $this->actingAs(shopper())->getJson('/api/admin/reports/sales'.$query)->assertForbidden();
        $this->actingAs(shopper())->getJson('/api/admin/reports/sales/export'.$query)->assertForbidden();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/reports/sales'.$query)->assertOk();
    });

    it('covers any date range, with every day present and the products sold', function () {
        $product = Product::factory()->priced(250000)->stock(10)->create(['name' => 'Report product']);
        $order = paidOrderAt('2026-10-05 04:00:00', [[$product, 2]]);

        $data = $this->actingAs(withRole('staff'))->getJson('/api/admin/reports/sales?from=2026-10-01&to=2026-10-10')->assertOk()->json('data');

        expect($data['days'])->toHaveCount(10)
            ->and(collect($data['days'])->firstWhere('date', '2026-10-05')['gross']['amount'])->toBe($order->total->sen)
            ->and($data['totals']['orders_paid'])->toBe(1)
            ->and($data['totals']['net']['amount'])->toBe($order->total->sen)
            ->and($data['products'][0]['name'])->toBe('Report product')
            ->and($data['products'][0]['units'])->toBe(2)
            ->and($data['period'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-10']);
    });

    it('checks the dates', function (string $query) {
        $this->actingAs(withRole('staff'))->getJson('/api/admin/reports/sales'.$query)->assertUnprocessable();
    })->with([
        'no dates' => [''],
        'wrong format' => ['?from=1/10/2026&to=10/10/2026'],
        'the end before the start' => ['?from=2026-10-10&to=2026-10-01'],
        'longer than a year' => ['?from=2025-01-01&to=2026-10-20'],
    ]);
});

describe('the CSV export', function () {
    it('has one row per day and a total, in ringgit with two decimals', function () {
        $order = paidOrderAt('2026-10-19 05:00:00');

        $response = $this->actingAs(withRole('staff'))->get('/api/admin/reports/sales/export?from=2026-10-18&to=2026-10-20')->assertOk();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));

        $ringgit = number_format($order->total->sen / 100, 2, '.', '');
        expect($response->headers->get('Content-Type'))->toContain('text/csv')
            ->and($response->headers->get('Content-Disposition'))->toContain('sales-2026-10-18-to-2026-10-20.csv')
            ->and($lines[0])->toBe(['Date', 'Orders placed', 'Orders paid', 'Gross sales (RM)', 'Refunded (RM)', 'Net sales (RM)'])
            ->and($lines)->toHaveCount(1 + 3 + 1)
            ->and($lines[2])->toBe(['2026-10-19', '1', '1', $ringgit, '0.00', $ringgit])
            ->and($lines[4])->toBe(['Total', '1', '1', $ringgit, '0.00', $ringgit]);
    });
});
