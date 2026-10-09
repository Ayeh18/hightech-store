<?php

use App\Enums\MalaysianState;
use App\Models\Address;
use Illuminate\Database\QueryException;

function validAddress(array $overrides = []): array
{
    return [
        'label' => 'Home',
        'recipient_name' => 'Aisyah Rahman',
        'phone' => '012-3456789',
        'line1' => '12 Jalan Contoh',
        'city' => 'Petaling Jaya',
        'state' => 'Selangor',
        'postcode' => '47810',
        ...$overrides,
    ];
}

it('requires login', function () {
    $address = Address::factory()->create();

    $this->getJson('/api/addresses')->assertUnauthorized();
    $this->postJson('/api/addresses', validAddress())->assertUnauthorized();
    $this->putJson("/api/addresses/{$address->id}", [])->assertUnauthorized();
    $this->deleteJson("/api/addresses/{$address->id}")->assertUnauthorized();
});

it('saves an address, and the first one becomes the default', function () {
    $user = shopper();

    $this->actingAs($user)->postJson('/api/addresses', validAddress())->assertCreated()
        ->assertJsonPath('data.recipient_name', 'Aisyah Rahman')
        ->assertJsonPath('data.country', 'MY')
        ->assertJsonPath('data.is_default', true);
});

it('keeps exactly one default: a new default replaces the old one', function () {
    $user = shopper();
    $first = $this->actingAs($user)->postJson('/api/addresses', validAddress())->json('data.id');
    $second = $this->actingAs($user)->postJson('/api/addresses', validAddress(['label' => 'Office']))->json('data'); // not the default
    expect($second['is_default'])->toBeFalse();

    $this->actingAs($user)->putJson("/api/addresses/{$second['id']}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);

    expect(Address::find($first)->is_default)->toBeFalse()->and(Address::where('user_id', $user->id)->where('is_default', true)->count())->toBe(1);
    $this->actingAs($user)->getJson('/api/addresses')->assertJsonPath('data.0.id', $second['id']); // the default is listed first
});

it('cannot stop being the default without another one being chosen', function () {
    $user = shopper();
    $only = $this->actingAs($user)->postJson('/api/addresses', validAddress())->json('data.id');

    $this->actingAs($user)->putJson("/api/addresses/{$only}", ['is_default' => false])->assertOk()->assertJsonPath('data.is_default', true);
});

it('promotes another address when the default is deleted', function () {
    $user = shopper();
    $a = $this->actingAs($user)->postJson('/api/addresses', validAddress())->json('data.id');
    $b = $this->actingAs($user)->postJson('/api/addresses', validAddress(['label' => 'Office']))->json('data.id');

    $this->actingAs($user)->deleteJson("/api/addresses/{$a}")->assertNoContent();

    expect(Address::find($b)->is_default)->toBeTrue();
});

it('edits an address', function () {
    $user = shopper();
    $id = $this->actingAs($user)->postJson('/api/addresses', validAddress())->json('data.id');

    $this->actingAs($user)->putJson("/api/addresses/{$id}", ['city' => 'Shah Alam', 'postcode' => '40000'])->assertOk()
        ->assertJsonPath('data.city', 'Shah Alam')->assertJsonPath('data.line1', '12 Jalan Contoh');
});

it('validates addresses', function (array $overrides, string $field) {
    $this->actingAs(shopper())->postJson('/api/addresses', validAddress($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no name' => [['recipient_name' => ''], 'recipient_name'],
    'bad phone' => [['phone' => 'call me'], 'phone'],
    'no street' => [['line1' => ''], 'line1'],
    'no city' => [['city' => ''], 'city'],
    'unknown state' => [['state' => 'Narnia'], 'state'],
    'short postcode' => [['postcode' => '4781'], 'postcode'],
    'letters in postcode' => [['postcode' => 'ABCDE'], 'postcode'],
]);

it('accepts every Malaysian state and territory', function (string $state) {
    $this->actingAs(shopper())->postJson('/api/addresses', validAddress(['state' => $state]))->assertCreated();
})->with(MalaysianState::values());

it('keeps addresses private: others get a 404 and never see yours', function () {
    $owner = shopper();
    $id = $this->actingAs($owner)->postJson('/api/addresses', validAddress())->json('data.id');
    $intruder = shopper();

    $this->actingAs($intruder)->putJson("/api/addresses/{$id}", ['city' => 'Hacked'])->assertNotFound();
    $this->actingAs($intruder)->deleteJson("/api/addresses/{$id}")->assertNotFound();
    $this->actingAs($intruder)->getJson('/api/addresses')->assertJsonCount(0, 'data');
    expect(Address::find($id)->city)->toBe('Petaling Jaya');
});

it('limits how many addresses one customer can save', function () {
    $user = shopper();
    Address::factory()->count((int) config('shop.max_addresses'))->for($user)->create();

    $this->actingAs($user)->postJson('/api/addresses', validAddress())->assertUnprocessable()->assertJsonValidationErrors('address');
});

it('lets the database enforce one default per customer even if application code is bypassed', function () {
    $user = shopper();
    Address::factory()->for($user)->default()->create();

    expect(fn () => Address::factory()->for($user)->default()->create())->toThrow(QueryException::class);
});
