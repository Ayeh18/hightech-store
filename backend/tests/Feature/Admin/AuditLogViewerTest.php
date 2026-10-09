<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

describe('the audit log', function () {
    it('is for admins only', function () {
        $this->getJson('/api/admin/audit-logs')->assertUnauthorized();
        $this->actingAs(shopper())->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/audit-logs/actions')->assertForbidden();
        $this->actingAs(withRole('admin'))->getJson('/api/admin/audit-logs')->assertOk();
    });

    it('lists what happened, newest first, with who did it and to what', function () {
        $order = paidOrder();
        $staff = withRole('staff');
        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PROCESSING'])->assertOk();

        $rows = $this->actingAs(withRole('admin'))->getJson('/api/admin/audit-logs')->assertOk()->json('data');

        expect($rows[0]['action'])->toBe('order.status_changed')
            ->and($rows[0]['actor']['email'])->toBe($staff->email)
            ->and($rows[0]['subject'])->toBe(['type' => 'Order', 'id' => $order->id])
            ->and($rows[0]['old_values'])->toBe(['status' => 'PAID'])
            ->and($rows[0]['new_values'])->toBe(['status' => 'PROCESSING'])
            ->and($rows[0])->toHaveKeys(['at', 'ip_address']);
    });

    it('shows actions the system took on its own, with no actor', function () {
        paidOrder(); // the payment webhook marks it paid: nobody is logged in

        $rows = collect($this->actingAs(withRole('admin'))->getJson('/api/admin/audit-logs?action=order.paid')->assertOk()->json('data'));

        expect($rows)->toHaveCount(1)->and($rows[0]['actor'])->toBeNull();
    });

    it('filters by action, by who did it, by what it was done to and by date', function () {
        $admin = withRole('admin');
        $order = paidOrder();
        $this->actingAs($admin)->postJson('/api/admin/coupons', ['code' => 'AUDIT10', 'type' => 'percent', 'value' => 1000])->assertCreated();
        $product = Product::factory()->create();
        $old = AuditLog::create(['user_id' => $admin->id, 'action' => 'product.updated', 'subject_type' => Product::class, 'subject_id' => (string) $product->id]);
        DB::table('audit_logs')->where('id', $old->id)->update(['created_at' => '2026-01-05 03:00:00']); // 11:00 on 5 January in Malaysia

        $get = fn (string $q) => $this->actingAs($admin)->getJson('/api/admin/audit-logs?'.$q)->assertOk();

        $get('action=coupon.created')->assertJsonCount(1, 'data');
        $get('q='.urlencode($admin->email))->assertJsonPath('data.0.actor.email', $admin->email);
        $get('subject=Product')->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'product.updated');
        $get('subject=Order')->assertJsonPath('data.0.subject.type', 'Order');
        $get('from=2026-01-05&to=2026-01-05')->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'product.updated');
        $get('from=2026-01-06&to=2026-01-06')->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/admin/audit-logs?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/admin/audit-logs?subject=Or%20der')->assertUnprocessable(); // not a plain word
        expect($order->id)->not->toBeEmpty();
    });

    it('lists the recorded actions for the filter box', function () {
        paidOrder();
        withRole('admin');

        $actions = $this->actingAs(withRole('admin'))->getJson('/api/admin/audit-logs/actions')->assertOk()->json('data');

        expect($actions)->toContain('order.created', 'order.paid', 'payment.initiated')->and($actions)->toBe(collect($actions)->sort()->values()->all());
    });

    it('is read-only: there is no way to change or remove an entry through the API', function () {
        $admin = withRole('admin');
        $entry = AuditLog::create(['user_id' => $admin->id, 'action' => 'test.entry']);

        $this->actingAs($admin)->putJson("/api/admin/audit-logs/{$entry->id}", ['action' => 'x'])->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/admin/audit-logs/{$entry->id}")->assertNotFound();
        $this->actingAs($admin)->postJson('/api/admin/audit-logs', ['action' => 'x'])->assertStatus(405);
        expect(AuditLog::find($entry->id)->action)->toBe('test.entry');
    });

    it('does not run more queries for more entries (no N+1)', function () {
        $admin = withRole('admin');
        paidOrder();
        $this->actingAs($admin)->getJson('/api/admin/audit-logs')->assertOk();

        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/audit-logs')->assertOk();
        $before = count(DB::getQueryLog());
        foreach (range(1, 3) as $_) {
            paidOrder();
        }
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/audit-logs')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($before);
    });

    it('pages through the entries', function () {
        $admin = withRole('admin');
        foreach (range(1, 30) as $i) {
            AuditLog::create(['user_id' => $admin->id, 'action' => 'test.entry']);
        }

        $page = $this->actingAs($admin)->getJson('/api/admin/audit-logs?action=test.entry')->assertOk();
        expect($page->json('data'))->toHaveCount(25)->and($page->json('meta.total'))->toBe(30);
        $this->actingAs($admin)->getJson('/api/admin/audit-logs?action=test.entry&page=2')->assertJsonCount(5, 'data');
        expect(User::count())->toBeGreaterThan(0);
    });
});
