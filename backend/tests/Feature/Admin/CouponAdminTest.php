<?php

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Product;
use App\Support\Money;

function validCoupon(array $overrides = []): array
{
    return ['code' => 'SUMMER10', 'type' => 'percent', 'value' => 1000, ...$overrides];
}

describe('who can manage coupons', function () {
    it('is admins only', function () {
        $coupon = Coupon::factory()->create();

        $this->getJson('/api/admin/coupons')->assertUnauthorized();
        foreach ([shopper(), withRole('staff')] as $user) {
            $this->actingAs($user)->getJson('/api/admin/coupons')->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/coupons', validCoupon())->assertForbidden();
            $this->actingAs($user)->putJson("/api/admin/coupons/{$coupon->id}", validCoupon())->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/admin/coupons/{$coupon->id}")->assertForbidden();
        }
        expect(Coupon::count())->toBe(1);
    });
});

describe('creating a coupon', function () {
    it('creates a percentage coupon, storing the code in capitals', function () {
        $admin = withRole('admin');

        $this->actingAs($admin)->postJson('/api/admin/coupons', validCoupon([
            'code' => ' summer10 ', 'description' => '10% off', 'min_order_amount' => 100000, 'max_discount_amount' => 20000,
            'usage_limit' => 100, 'per_user_limit' => 2,
        ]))->assertCreated()
            ->assertJsonPath('data.code', 'SUMMER10')
            ->assertJsonPath('data.value_display', '10%')
            ->assertJsonPath('data.min_order_amount.formatted', 'RM 1,000.00')
            ->assertJsonPath('data.max_discount_amount.amount', 20000)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.used_count', 0);

        expect(Coupon::sole()->code)->toBe('SUMMER10');
        $log = AuditLog::where('action', 'coupon.created')->sole();
        expect($log->user_id)->toBe($admin->id)->and($log->new_values['code'])->toBe('SUMMER10');
    });

    it('creates a fixed-amount coupon', function () {
        $this->actingAs(withRole('admin'))->postJson('/api/admin/coupons', validCoupon(['code' => 'SAVE50', 'type' => 'fixed', 'value' => 5000]))->assertCreated()
            ->assertJsonPath('data.value_display', 'RM 50.00')->assertJsonPath('data.max_discount_amount', null);
    });

    it('shows half-percent values properly', function () {
        $this->actingAs(withRole('admin'))->postJson('/api/admin/coupons', validCoupon(['value' => 1250]))->assertCreated()->assertJsonPath('data.value_display', '12.5%');
    });

    it('refuses what makes no sense', function (array $bad, string $field) {
        $this->actingAs(withRole('admin'))->postJson('/api/admin/coupons', validCoupon($bad))->assertUnprocessable()->assertJsonValidationErrors($field);
        expect(Coupon::count())->toBe(0);
    })->with([
        'no code' => [['code' => ''], 'code'],
        'a code with spaces and symbols' => [['code' => 'BIG SALE!'], 'code'],
        'a code that is too short' => [['code' => 'AB'], 'code'],
        'no type' => [['type' => null], 'type'],
        'an unknown type' => [['type' => 'bogus'], 'type'],
        'more than 100% off' => [['value' => 10001], 'value'],
        'zero off' => [['value' => 0], 'value'],
        'a negative minimum order' => [['min_order_amount' => -1], 'min_order_amount'],
        'a cap on a fixed coupon' => [['type' => 'fixed', 'value' => 5000, 'max_discount_amount' => 1000], 'max_discount_amount'],
        'ending before it starts' => [['starts_at' => '2026-10-10', 'ends_at' => '2026-10-01'], 'ends_at'],
        'a usage limit of zero' => [['usage_limit' => 0], 'usage_limit'],
        'a per-customer limit of zero' => [['per_user_limit' => 0], 'per_user_limit'],
    ]);

    it('does not allow the same code twice, whatever the capitals', function () {
        $admin = withRole('admin');
        $this->actingAs($admin)->postJson('/api/admin/coupons', validCoupon())->assertCreated();

        $this->actingAs($admin)->postJson('/api/admin/coupons', validCoupon(['code' => 'summer10']))->assertUnprocessable()->assertJsonValidationErrors('code');
        expect(Coupon::count())->toBe(1);
    });
});

describe('the list', function () {
    it('shows each coupon with how often it was used and whether it works right now', function () {
        $admin = withRole('admin');
        $active = Coupon::factory()->create(['code' => 'ACTIVE1']);
        Coupon::factory()->create(['code' => 'OFFLINE', 'is_active' => false]);
        Coupon::factory()->create(['code' => 'SOONER', 'starts_at' => now()->addDay()]);
        Coupon::factory()->create(['code' => 'GONE', 'ends_at' => now()->subDay()]);
        $usedUp = Coupon::factory()->create(['code' => 'ONLY1', 'usage_limit' => 1]);
        $order = unpaidOrder();
        CouponUsage::create(['coupon_id' => $usedUp->id, 'user_id' => $order->user_id, 'order_id' => $order->id, 'discount_amount' => Money::fromSen(100)]);
        // A released use (its order was cancelled) does not count.
        $other = unpaidOrder();
        CouponUsage::create(['coupon_id' => $active->id, 'user_id' => $other->user_id, 'order_id' => $other->id, 'discount_amount' => Money::fromSen(100), 'released_at' => now()]);

        $rows = collect($this->actingAs($admin)->getJson('/api/admin/coupons')->assertOk()->json('data'))->keyBy('code');

        expect($rows['ACTIVE1']['status'])->toBe('active')->and($rows['ACTIVE1']['used_count'])->toBe(0)
            ->and($rows['OFFLINE']['status'])->toBe('inactive')
            ->and($rows['SOONER']['status'])->toBe('scheduled')
            ->and($rows['GONE']['status'])->toBe('expired')
            ->and($rows['ONLY1']['status'])->toBe('used_up')->and($rows['ONLY1']['used_count'])->toBe(1);
    });

    it('searches by code, and treats % as plain text', function () {
        Coupon::factory()->create(['code' => 'WELCOME10']);
        Coupon::factory()->create(['code' => 'SAVE50']);
        $admin = withRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/coupons?q=welc')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'WELCOME10');
        $this->actingAs($admin)->getJson('/api/admin/coupons?q=%25')->assertJsonCount(0, 'data');
    });

    it('does not run more queries for more coupons (no N+1)', function () {
        Coupon::factory()->create();
        $admin = withRole('admin');
        $this->actingAs($admin)->getJson('/api/admin/coupons')->assertOk();

        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/coupons')->assertOk();
        $before = count(DB::getQueryLog());
        Coupon::factory()->count(5)->create();
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/coupons')->assertOk()->assertJsonCount(6, 'data');

        expect(count(DB::getQueryLog()))->toBe($before);
    });
});

describe('editing and deleting', function () {
    it('changes a coupon and records what it was before', function () {
        $coupon = Coupon::factory()->create(['code' => 'EDITME', 'type' => 'percent', 'value' => 1000]);
        $admin = withRole('admin');

        $this->actingAs($admin)->putJson("/api/admin/coupons/{$coupon->id}", validCoupon(['code' => 'EDITME', 'value' => 2000, 'is_active' => false]))->assertOk()
            ->assertJsonPath('data.value_display', '20%')->assertJsonPath('data.status', 'inactive');

        $log = AuditLog::where('action', 'coupon.updated')->sole();
        expect($log->old_values['value'])->toBe(1000)->and($log->new_values['value'])->toBe(2000)->and($log->new_values['is_active'])->toBeFalse();
    });

    it('keeps its own code when saved again, but cannot take another coupon\'s code', function () {
        $one = Coupon::factory()->create(['code' => 'ONE111']);
        Coupon::factory()->create(['code' => 'TWO222']);
        $admin = withRole('admin');

        $this->actingAs($admin)->putJson("/api/admin/coupons/{$one->id}", validCoupon(['code' => 'ONE111']))->assertOk();
        $this->actingAs($admin)->putJson("/api/admin/coupons/{$one->id}", validCoupon(['code' => 'two222']))->assertUnprocessable()->assertJsonValidationErrors('code');
    });

    it('deletes a coupon nobody has used', function () {
        $coupon = Coupon::factory()->create();
        $admin = withRole('admin');

        $this->actingAs($admin)->deleteJson("/api/admin/coupons/{$coupon->id}")->assertNoContent();

        expect(Coupon::count())->toBe(0)->and(AuditLog::where('action', 'coupon.deleted')->count())->toBe(1);
    });

    it('will not delete a coupon that was used on an order: switch it off instead', function () {
        $coupon = Coupon::factory()->create();
        $order = unpaidOrder();
        CouponUsage::create(['coupon_id' => $coupon->id, 'user_id' => $order->user_id, 'order_id' => $order->id, 'discount_amount' => Money::fromSen(100)]);

        $this->actingAs(withRole('admin'))->deleteJson("/api/admin/coupons/{$coupon->id}")->assertUnprocessable()->assertJsonValidationErrors('coupon');
        expect(Coupon::count())->toBe(1);
    });
});

it('creates coupons that really work at checkout, and switching one off stops it', function () {
    $admin = withRole('admin');
    $this->actingAs($admin)->postJson('/api/admin/coupons', validCoupon(['code' => 'launch25', 'value' => 2500, 'min_order_amount' => 50000]))->assertCreated();
    $couponId = Coupon::sole()->id;
    $product = Product::factory()->priced(100000)->stock(10)->create();
    $customer = shopper();
    addToCart($customer, $product)->assertCreated();
    $address = Address::factory()->for($customer)->create();

    $this->actingAs($customer)->postJson('/api/checkout/preview', ['address_id' => $address->id, 'coupon_code' => 'Launch25'])->assertOk()
        ->assertJsonPath('data.discount.amount', 25000)->assertJsonPath('data.coupon.code', 'LAUNCH25');

    $this->actingAs($admin)->putJson("/api/admin/coupons/{$couponId}", validCoupon(['code' => 'LAUNCH25', 'value' => 2500, 'is_active' => false]))->assertOk();

    $this->actingAs($customer)->postJson('/api/checkout/preview', ['address_id' => $address->id, 'coupon_code' => 'LAUNCH25'])->assertOk()
        ->assertJsonPath('data.coupon.applied', false)->assertJsonPath('data.coupon.error', 'That coupon code is not valid.')->assertJsonPath('data.discount.amount', 0);
});
