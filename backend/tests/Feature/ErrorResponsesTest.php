<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| The API promises one shape for every error: { "message": "..." }, plus "errors" for a form that failed validation.
| Nothing about the code (class names, files, stack traces) may ever reach a visitor, and that must hold even with
| APP_DEBUG=true, the setting in which Laravel is most talkative.
*/

/** The keys Laravel adds to an error when it describes the exception itself. */
const INTERNAL_KEYS = ['exception', 'file', 'line', 'trace'];

beforeEach(function () {
    Route::get('/api/_test/boom', fn () => throw new RuntimeException('SQLSTATE[08006] password authentication failed for user "shop"'));
    Route::get('/api/_test/abort/{status}', fn (int $status) => abort($status, 'A message written for the visitor.'));
    Route::get('/api/_test/gateway-down', fn () => throw new HttpException(502, 'upstream 10.0.0.7:8443 refused'));
});

it('answers the wrong HTTP method with a clean 405, with or without debug mode', function (bool $debug) {
    config(['app.debug' => $debug]);

    $this->deleteJson('/api/auth/login')
        ->assertStatus(405)
        ->assertExactJson(['message' => 'That action is not available at this address.'])
        ->assertHeader('Allow');
})->with(['debug on' => true, 'debug off' => false]);

it('answers too many attempts with a clean 429 that still says when to try again', function (bool $debug) {
    config(['app.debug' => $debug]);
    $user = User::factory()->create();

    foreach (range(1, 20) as $attempt) {
        $this->actingAs($user)->postJson('/api/checkout', []);
    }

    $this->actingAs($user)->postJson('/api/checkout', [])
        ->assertStatus(429)
        ->assertExactJson(['message' => 'Too many attempts. Please wait a moment and try again.'])
        ->assertHeader('Retry-After');
})->with(['debug on' => true, 'debug off' => false]);

it('passes on a message the shop wrote for the visitor, and nothing else', function (int $status) {
    config(['app.debug' => true]);

    $this->getJson("/api/_test/abort/{$status}")
        ->assertStatus($status)
        ->assertExactJson(['message' => 'A message written for the visitor.']);
})->with([400, 409, 422, 503]);

it('never passes on the message of a server-side failure, even one raised with a status', function () {
    config(['app.debug' => true]);

    $this->getJson('/api/_test/gateway-down')
        ->assertStatus(502)
        ->assertExactJson(['message' => 'Something went wrong on our side. Please try again shortly.']);
});

it('hides an unexpected failure completely when debug mode is off, as it is on a real server', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/_test/boom')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Something went wrong on our side. Please try again shortly.']);

    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('RuntimeException')->not->toContain('.php');
});

it('keeps the usual shapes for the errors Laravel answers itself', function (bool $debug) {
    config(['app.debug' => $debug]);

    $guest = $this->getJson('/api/orders')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $invalid = $this->getJson('/api/products?per_page=999')->assertUnprocessable()->assertJsonStructure(['message', 'errors' => ['per_page']]);
    $missing = $this->getJson('/api/products/no-such-product')->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);

    foreach ([$guest, $invalid, $missing] as $response) {
        expect(array_intersect(array_keys($response->json()), INTERNAL_KEYS))->toBe([]);
    }
})->with(['debug on' => true, 'debug off' => false]);

it('answers a forbidden action the same way however it was refused', function () {
    config(['app.debug' => true]);
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)->getJson('/api/admin/dashboard')
        ->assertForbidden()
        ->assertExactJson(['message' => 'You do not have permission to perform this action.']);
});
