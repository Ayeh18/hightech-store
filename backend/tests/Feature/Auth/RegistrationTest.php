<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Aisyah Rahman',
        'email' => 'aisyah@example.com',
        'phone' => '012-3456789',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
    ], $overrides);
}

it('registers a customer, logs them in and hashes the password', function () {
    $response = $this->spa()->postJson('/api/auth/register', validRegistration());

    $response->assertCreated()
        ->assertJsonPath('data.email', 'aisyah@example.com')
        ->assertJsonPath('data.roles', ['customer'])
        ->assertJsonPath('data.permissions', [])
        ->assertJsonMissingPath('data.password');

    $user = User::firstWhere('email', 'aisyah@example.com');
    expect(Hash::check('Secret123', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('Secret123');

    $this->assertAuthenticatedAs($user);
    expect(AuditLog::where('action', 'auth.registered')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('validates registration input', function (array $overrides, string $field) {
    $this->spa()->postJson('/api/auth/register', validRegistration($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'short password' => [['password' => 'Ab1', 'password_confirmation' => 'Ab1'], 'password'],
    'password without number' => [['password' => 'OnlyLetters', 'password_confirmation' => 'OnlyLetters'], 'password'],
    'password mismatch' => [['password_confirmation' => 'Different123'], 'password'],
    'bad phone' => [['phone' => 'abc'], 'phone'],
]);

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'aisyah@example.com']);

    $this->spa()->postJson('/api/auth/register', validRegistration())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});
