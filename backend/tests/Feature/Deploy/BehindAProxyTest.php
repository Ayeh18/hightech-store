<?php

use App\Models\AuditLog;
use App\Models\User;

it('records the real visitor\'s address when a host\'s proxy forwards it, not the proxy\'s', function () {
    $user = User::factory()->create(['email' => 'visitor@shop.test']);
    $user->assignRole('customer');

    $this->spa()
        ->withServerVariables(['REMOTE_ADDR' => '10.20.30.40']) // the host's proxy
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->postJson('/api/auth/login', ['email' => 'visitor@shop.test', 'password' => 'Password123'])
        ->assertOk();

    expect(AuditLog::where('action', 'auth.login')->sole()->ip_address)->toBe('203.0.113.9');
});

it('knows a request that arrived through a proxy over https is secure', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'shop.example'])
        ->getJson('/api/categories')->assertOk();

    expect(request()->isSecure())->toBeTrue();
});
