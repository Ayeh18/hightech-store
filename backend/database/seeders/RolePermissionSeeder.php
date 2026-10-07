<?php

namespace Database\Seeders;

use App\Enums\Role as RoleSlug;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Safe to run repeatedly (and in production): it only creates/updates roles and permissions. */
class RolePermissionSeeder extends Seeder
{
    /** @var array<string, string> permission key => description */
    public const PERMISSIONS = [
        'admin.access' => 'Open the admin area',
        'products.manage' => 'Create, edit and delete products',
        'categories.manage' => 'Manage categories',
        'brands.manage' => 'Manage brands',
        'inventory.manage' => 'Adjust stock levels',
        'compatibility.manage' => 'Manage compatibility rules',
        'orders.manage' => 'View and update orders and shipments',
        'refunds.manage' => 'Initiate and review refunds',
        'payments.view' => 'View payment transactions and reconciliation',
        'coupons.manage' => 'Manage coupons',
        'users.manage' => 'Manage users and their roles',
        'reports.view' => 'View the dashboard and reports',
        'audit.view' => 'View audit logs',
    ];

    /** Staff get day-to-day operations; admins get everything. Customers need no admin permissions. */
    private const STAFF_PERMISSIONS = [
        'admin.access', 'products.manage', 'categories.manage', 'brands.manage',
        'inventory.manage', 'orders.manage', 'reports.view',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $key => $description) {
            Permission::updateOrCreate(['key' => $key], ['description' => $description]);
        }

        $roles = [
            RoleSlug::Customer->value => ['Customer', []],
            RoleSlug::Staff->value => ['Staff', self::STAFF_PERMISSIONS],
            RoleSlug::Admin->value => ['Admin', array_keys(self::PERMISSIONS)],
        ];

        foreach ($roles as $slug => [$name, $permissionKeys]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
            $role->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id'));
        }
    }
}
