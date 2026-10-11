<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('blocks guests from the admin area', function () {
    $this->getJson('/api/admin/dashboard')->assertUnauthorized();
});

it('forbids customers from the admin area', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    // The body must be a plain message: no exception class, file path or stack trace.
    $this->actingAs($customer)->getJson('/api/admin/dashboard')
        ->assertForbidden()
        ->assertExactJson(['message' => 'You do not have permission to perform this action.']);
});

it('lets staff and admin into the admin area', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->getJson('/api/admin/dashboard')->assertOk();
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

it('rejects state-changing requests without a CSRF token using a clean 419 body', function () {
    // Real CSRF checks are skipped while unit testing, so exercise the exception handler directly.
    Route::post('/api/_test/csrf', fn () => throw new HttpException(419, 'CSRF token mismatch.'));

    $this->postJson('/api/_test/csrf')
        ->assertStatus(419)
        ->assertExactJson(['message' => 'Your session expired. Please refresh and try again.']);
});
