<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\CouponSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;

/**
 * Puts a freshly migrated database into a usable state. Run on every deploy, and safe to run again and again:
 *
 *  - roles, permissions and compatibility rules are created if missing (never overwritten);
 *  - the first admin is created from ADMIN_EMAIL / ADMIN_PASSWORD, but only if that person does not exist yet (an existing
 *    account, and its password, is never touched);
 *  - the demo catalog (products, brands, coupons) is added ONLY when there are no products at all. The catalog seeder resets
 *    stock and prices, so running it on a shop that is already trading would damage real data.
 */
class InstallShop extends Command
{
    protected $signature = 'shop:install {--demo-catalog : Fill an empty catalog with the demo products}';

    protected $description = 'Prepare a freshly migrated database: roles, rules, the first admin, and optionally a demo catalog';

    private const MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => CompatibilityRuleSeeder::class, '--force' => true]);
        $this->info('Roles, permissions and compatibility rules are in place.');

        $failed = ! $this->createAdmin();
        $this->seedCatalog();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return bool false when the settings were given but unusable (so the deploy shows the problem instead of hiding it) */
    private function createAdmin(): bool
    {
        $email = trim((string) config('shop.install.admin_email'));
        $password = (string) config('shop.install.admin_password');

        if ($email === '' && $password === '') {
            $this->line('No ADMIN_EMAIL / ADMIN_PASSWORD set: no admin was created.');

            return true;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('ADMIN_EMAIL must be a valid address and ADMIN_PASSWORD at least '.self::MIN_PASSWORD_LENGTH.' characters. No admin was created.');

            return false;
        }

        if (User::where('email', $email)->exists()) {
            $this->line("{$email} already exists: left exactly as it is.");

            return true;
        }

        $user = User::create([
            'name' => (string) config('shop.install.admin_name'),
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(Role::Admin);
        $this->info("Admin {$email} created.");

        return true;
    }

    private function seedCatalog(): void
    {
        if (! $this->option('demo-catalog') && ! config('shop.install.demo_catalog')) {
            return;
        }

        if (Product::withTrashed()->exists()) {
            $this->line('The catalog already has products: the demo catalog was NOT added (that would reset real stock and prices).');

            return;
        }

        $this->call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => CouponSeeder::class, '--force' => true]);
        $this->info('The demo catalog and coupons were added.');
    }
}
