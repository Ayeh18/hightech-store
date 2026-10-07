<?php

use App\Models\AuditLog;
use App\Models\User;

it('logs in with valid credentials and records an audit entry', function () {
    $user = User::factory()->create(['email' => 'jane@example.com', 'password' => 'Secret123']);
    $user->assignRole('customer');

    $this->spa()->postJson('/api/auth/login', ['email' => 'jane@example.com', 'password' => 'Secret123'])
        ->assertOk()
        ->assertJsonPath('data.email', 'jane@example.com');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'auth.login')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('rejects a wrong password with a validation-style error', function () {
    User::factory()->create(['email' => 'jane@example.com', 'password' => 'Secret123']);

    $this->spa()->postJson('/api/auth/login', ['email' => 'jane@example.com', 'password' => 'Wrong12345'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('does not let a deactivated user log in', function () {
    User::factory()->create(['email' => 'jane@example.com', 'password' => 'Secret123', 'is_active' => false]);

    $this->spa()->postJson('/api/auth/login', ['email' => 'jane@example.com', 'password' => 'Secret123'])
        ->assertUnprocessable();

    $this->assertGuest();
});

it('locks out after too many failed attempts', function () {
    User::factory()->create(['email' => 'jane@example.com', 'password' => 'Secret123']);

    foreach (range(1, 5) as $_) {
        $this->spa()->postJson('/api/auth/login', ['email' => 'jane@example.com', 'password' => 'bad'])
            ->assertUnprocessable();
    }

    // Even the correct password is refused while locked out.
    $this->spa()->postJson('/api/auth/login', ['email' => 'jane@example.com', 'password' => 'Secret123'])
        ->assertStatus(429);
});

it('logs out, clears the web session and records an audit entry', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Secret123'])->assertOk();
    expect(auth('web')->check())->toBeTrue();

    $this->spa()->postJson('/api/auth/logout')->assertNoContent();

    expect(auth('web')->check())->toBeFalse()
        ->and(AuditLog::where('action', 'auth.logout')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('requires authentication for /me and returns roles and permissions', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.roles', ['admin'])
        ->assertJsonFragment(['permissions' => $admin->permissionKeys()]);

    expect($admin->permissionKeys())->toContain('admin.access', 'users.manage');
});
