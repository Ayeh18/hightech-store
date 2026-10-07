<?php

use App\Models\User;

it('blocks guests from the admin area', function () {
    $this->getJson('/api/admin/ping')->assertUnauthorized();
});

it('forbids customers from the admin area', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)->getJson('/api/admin/ping')->assertForbidden();
});

it('lets staff and admin into the admin area', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->getJson('/api/admin/ping')->assertOk()->assertJson(['message' => 'pong']);
})->with(['staff', 'admin']);

it('gives staff fewer permissions than admin', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect($staff->hasPermission('products.manage'))->toBeTrue()
        ->and($staff->hasPermission('users.manage'))->toBeFalse()
        ->and($staff->hasPermission('refunds.manage'))->toBeFalse()
        ->and($admin->hasPermission('refunds.manage'))->toBeTrue();
});

it('returns a consistent json body for unknown api routes', function () {
    $this->getJson('/api/does-not-exist')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);
});
