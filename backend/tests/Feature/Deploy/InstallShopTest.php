<?php

use App\Models\CompatibilityRule;
use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function installWith(array $settings = [], array $options = [])
{
    config(['shop.install' => array_merge(['admin_email' => null, 'admin_password' => null, 'admin_name' => 'Shop Admin', 'demo_catalog' => false], $settings)]);

    return test()->artisan('shop:install', $options);
}

it('sets up roles, permissions and compatibility rules on a bare database', function () {
    Role::query()->delete();
    Permission::query()->delete();

    installWith()->expectsOutputToContain('Roles, permissions and compatibility rules are in place.')->assertSuccessful();

    expect(Role::pluck('slug')->sort()->values()->all())->toBe(['admin', 'customer', 'staff'])
        ->and(Permission::count())->toBeGreaterThan(10)
        ->and(CompatibilityRule::count())->toBeGreaterThan(10);
});

it('creates the first admin from its settings, with a real hashed password and the admin role', function () {
    installWith(['admin_email' => 'owner@shop.test', 'admin_password' => 'a-long-secret-1', 'admin_name' => 'The Owner'])->assertSuccessful();

    $admin = User::where('email', 'owner@shop.test')->sole();
    expect($admin->name)->toBe('The Owner')
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->password)->not->toBe('a-long-secret-1')
        ->and(Hash::check('a-long-secret-1', $admin->password))->toBeTrue()
        ->and($admin->hasPermission('refunds.manage'))->toBeTrue();
});

it('never touches an account that already exists, so a redeploy cannot reset a password or demote anyone', function () {
    $existing = User::factory()->create(['email' => 'owner@shop.test', 'password' => 'my-own-password-9']);
    $existing->assignRole('customer');

    installWith(['admin_email' => 'owner@shop.test', 'admin_password' => 'a-different-secret-2'])->expectsOutputToContain('already exists: left exactly as it is')->assertSuccessful();

    $existing->refresh();
    expect(Hash::check('my-own-password-9', $existing->password))->toBeTrue()
        ->and($existing->hasRole('admin'))->toBeFalse()
        ->and(User::where('email', 'owner@shop.test')->count())->toBe(1);
});

it('can be run again and again without making a second admin', function () {
    installWith(['admin_email' => 'owner@shop.test', 'admin_password' => 'a-long-secret-1'])->assertSuccessful();
    installWith(['admin_email' => 'owner@shop.test', 'admin_password' => 'a-long-secret-1'])->assertSuccessful();

    expect(User::where('email', 'owner@shop.test')->count())->toBe(1)->and(Role::count())->toBe(3);
});

it('refuses a weak password or a bad address, and says so, creating nobody', function (string $email, string $password) {
    installWith(['admin_email' => $email, 'admin_password' => $password])->expectsOutputToContain('at least 12 characters')->assertFailed();

    expect(User::count())->toBe(0);
})->with([
    'short password' => ['owner@shop.test', 'short'],
    'no password' => ['owner@shop.test', ''],
    'bad address' => ['not-an-email', 'a-long-secret-1'],
]);

it('creates nobody when no admin is configured', function () {
    installWith()->expectsOutputToContain('no admin was created')->assertSuccessful();

    expect(User::count())->toBe(0);
});

describe('the demo catalog', function () {
    it('is added to an empty catalog, when asked', function () {
        installWith(options: ['--demo-catalog' => true])->expectsOutputToContain('demo catalog and coupons were added')->assertSuccessful();

        expect(Product::count())->toBeGreaterThan(100)->and(Coupon::where('code', 'WELCOME10')->exists())->toBeTrue();
    });

    it('can also be asked for through the settings', function () {
        installWith(['demo_catalog' => true])->assertSuccessful();

        expect(Product::count())->toBeGreaterThan(100);
    });

    it('is not added unless asked', function () {
        installWith()->assertSuccessful();

        expect(Product::count())->toBe(0);
    });

    it('is NEVER added over a shop that already has products, because that would reset real stock and prices', function () {
        $product = Product::factory()->priced(123400)->stock(7)->create();

        installWith(['demo_catalog' => true])->expectsOutputToContain('was NOT added')->assertSuccessful();

        expect(Product::count())->toBe(1)
            ->and($product->fresh()->price->sen)->toBe(123400)
            ->and(Inventory::where('product_id', $product->id)->value('stock'))->toBe(7);
    });

    it('also counts deleted products as a catalog that is in use', function () {
        Product::factory()->create()->delete();

        installWith(['demo_catalog' => true])->expectsOutputToContain('was NOT added')->assertSuccessful();

        expect(Product::withTrashed()->count())->toBe(1);
    });
});
