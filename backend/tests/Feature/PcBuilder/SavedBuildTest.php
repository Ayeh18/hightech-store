<?php

use App\Enums\BuilderSlot;
use App\Models\Inventory;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\Product;
use App\Models\User;
use App\Services\PcBuilder\PcBuildService;
use App\Support\Money;
use Illuminate\Database\QueryException;

function customer(): User
{
    $user = User::factory()->create();
    $user->assignRole('customer');

    return $user;
}

/** Saves a build for $user directly in the database (bypassing the API). */
function savedBuild(User $user, array $slots = ['cpu' => 50000, 'ram' => 20000], string $name = 'My Rig'): PcBuild
{
    $build = PcBuild::factory()->for($user)->create(['name' => $name]);

    foreach ($slots as $slot => $sen) {
        PcBuildItem::factory()->create([
            'pc_build_id' => $build->id,
            'product_id' => partFor(BuilderSlot::from($slot), $sen)->id,
            'slot' => $slot,
        ]);
    }

    return $build;
}

it('requires login for every saved-build endpoint', function () {
    $build = savedBuild(customer());

    $this->getJson('/api/builds')->assertUnauthorized();
    $this->postJson('/api/builds', ['name' => 'x', 'items' => []])->assertUnauthorized();
    $this->getJson("/api/builds/{$build->id}")->assertUnauthorized();
    $this->putJson("/api/builds/{$build->id}", ['name' => 'x'])->assertUnauthorized();
    $this->deleteJson("/api/builds/{$build->id}")->assertUnauthorized();
    $this->postJson("/api/builds/{$build->id}/duplicate")->assertUnauthorized();
});

it('saves a build with live prices, even when it is not complete yet', function () {
    $user = customer();
    $cpu = partFor(BuilderSlot::Cpu, 80000);
    $ram = partFor(BuilderSlot::Ram, 25000);

    $response = $this->actingAs($user)->postJson('/api/builds', [
        'name' => 'Gaming PC 2026',
        'items' => [buildItem(BuilderSlot::Cpu, $cpu), buildItem(BuilderSlot::Ram, $ram, 2)],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Gaming PC 2026')
        ->assertJsonPath('data.subtotal.amount', 130000)
        ->assertJsonPath('data.complete', false)
        ->assertJsonPath('data.items.1.quantity', 2);

    $build = PcBuild::findOrFail($response->json('data.id'));
    expect($build->user_id)->toBe($user->id)
        ->and($build->items)->toHaveCount(2)
        // The id is a ULID (not guessable like 1, 2, 3).
        ->and($build->id)->toMatch('/^[0-9a-z]{26}$/');
    $this->assertDatabaseMissing('pc_build_items', ['quantity' => 0]);
});

it('validates a new build', function (array $overrides, string $field) {
    $cpu = partFor(BuilderSlot::Cpu);
    $payload = array_merge(['name' => 'Rig', 'items' => [buildItem(BuilderSlot::Cpu, $cpu)]], $overrides);

    $this->actingAs(customer())->postJson('/api/builds', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('x', 101)], 'name'],
    'no items' => [['items' => []], 'items'],
    'part in the wrong slot' => [['items' => [['slot' => 'gpu', 'product_id' => 1]]], 'items.0.product_id'],
]);

it('does not save a build with a part in the wrong slot', function () {
    $gpu = partFor(BuilderSlot::Gpu);

    $this->actingAs(customer())->postJson('/api/builds', ['name' => 'Bad', 'items' => [buildItem(BuilderSlot::Cpu, $gpu)]])->assertUnprocessable();

    expect(PcBuild::count())->toBe(0);
});

it('limits how many builds one customer can keep', function () {
    $user = customer();
    PcBuild::factory()->count(PcBuildService::MAX_BUILDS_PER_USER)->for($user)->create();
    $cpu = partFor(BuilderSlot::Cpu);

    $this->actingAs($user)->postJson('/api/builds', ['name' => 'One too many', 'items' => [buildItem(BuilderSlot::Cpu, $cpu)]])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('lists only my builds, most recently edited first, with totals', function () {
    $me = customer();
    $older = savedBuild($me, ['cpu' => 40000], 'Older');
    $newer = savedBuild($me, ['cpu' => 60000, 'ram' => 10000], 'Newer');
    $older->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    savedBuild(customer(), ['cpu' => 99999], 'Someone elses');

    $response = $this->actingAs($me)->getJson('/api/builds')->assertOk()->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Newer', 'Older'])
        ->and($response->json('data.0.subtotal.amount'))->toBe(70000)
        ->and($response->json('data.0.item_count'))->toBe(2)
        ->and($response->json('data.0.parts.0.slot'))->toBe('cpu');
});

it('lists the parts of a build in builder order, whatever order they were saved in', function () {
    $me = customer();
    // Saved in the "wrong" order on purpose: psu first, cpu last.
    $build = savedBuild($me, ['psu' => 1000, 'ram' => 1000, 'cpu' => 1000]);

    $this->actingAs($me)->getJson('/api/builds')
        ->assertJsonPath('data.0.id', $build->id)
        ->assertJsonPath('data.0.parts.0.slot', 'cpu')
        ->assertJsonPath('data.0.parts.1.slot', 'ram')
        ->assertJsonPath('data.0.parts.2.slot', 'psu');
});

it('does not run extra queries per build in the list (no N+1)', function () {
    $me = customer();
    savedBuild($me, ['cpu' => 1000, 'ram' => 1000]);

    DB::enableQueryLog();
    $this->actingAs($me)->getJson('/api/builds')->assertOk();
    $withOne = count(DB::getQueryLog());

    foreach (range(1, 4) as $_) {
        savedBuild($me, ['cpu' => 1000, 'ram' => 1000, 'psu' => 1000]);
    }

    DB::flushQueryLog();
    $this->actingAs($me)->getJson('/api/builds')->assertOk()->assertJsonCount(5, 'data');

    expect(count(DB::getQueryLog()))->toBe($withOne);
});

it('shows my build with product details, and hides other peoples builds as not found', function () {
    $me = customer();
    $mine = savedBuild($me);
    $theirs = savedBuild(customer());

    $this->actingAs($me)->getJson("/api/builds/{$mine->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $mine->id)
        ->assertJsonPath('data.items.0.product.brand.name', Product::find($mine->items->first()->product_id)->brand->name);

    // 404, not 403: the response must not confirm that someone else's build exists.
    $this->actingAs($me)->getJson("/api/builds/{$theirs->id}")->assertNotFound();
    $this->actingAs($me)->getJson('/api/builds/not-a-real-id')->assertNotFound();
});

it('renames a build without touching its parts', function () {
    $me = customer();
    $build = savedBuild($me);

    $this->actingAs($me)->putJson("/api/builds/{$build->id}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonCount(2, 'data.items');
});

it('replaces the parts of a build when items are sent', function () {
    $me = customer();
    $build = savedBuild($me, ['cpu' => 50000, 'ram' => 20000]);
    $newCpu = partFor(BuilderSlot::Cpu, 70000);

    $this->actingAs($me)->putJson("/api/builds/{$build->id}", ['items' => [buildItem(BuilderSlot::Cpu, $newCpu)]])
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.product.id', $newCpu->id)
        ->assertJsonPath('data.subtotal.amount', 70000);

    expect($build->items()->count())->toBe(1);
});

it('keeps the old parts when an update is rejected', function () {
    $me = customer();
    $build = savedBuild($me);
    $gpu = partFor(BuilderSlot::Gpu);

    $this->actingAs($me)->putJson("/api/builds/{$build->id}", ['items' => [buildItem(BuilderSlot::Cpu, $gpu)]])->assertUnprocessable();

    expect($build->items()->count())->toBe(2);
});

it('does not let someone else edit, duplicate or delete my build', function () {
    $owner = customer();
    $build = savedBuild($owner);
    $intruder = customer();

    $this->actingAs($intruder)->putJson("/api/builds/{$build->id}", ['name' => 'Hacked'])->assertNotFound();
    $this->actingAs($intruder)->postJson("/api/builds/{$build->id}/duplicate")->assertNotFound();
    $this->actingAs($intruder)->deleteJson("/api/builds/{$build->id}")->assertNotFound();

    expect($build->fresh()->name)->toBe('My Rig');
});

it('does not let staff or admins read customers builds either', function () {
    $build = savedBuild(customer());
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->getJson("/api/builds/{$build->id}")->assertNotFound();
});

it('deletes a build and its parts', function () {
    $me = customer();
    $build = savedBuild($me);

    $this->actingAs($me)->deleteJson("/api/builds/{$build->id}")->assertNoContent();

    expect(PcBuild::find($build->id))->toBeNull()->and(PcBuildItem::where('pc_build_id', $build->id)->count())->toBe(0);
});

it('duplicates a build as "Copy of ..." with the same parts', function () {
    $me = customer();
    $build = savedBuild($me, ['cpu' => 50000, 'ram' => 20000], 'Gaming PC');

    $response = $this->actingAs($me)->postJson("/api/builds/{$build->id}/duplicate")
        ->assertCreated()->assertJsonPath('data.name', 'Copy of Gaming PC')->assertJsonCount(2, 'data.items');

    expect($response->json('data.id'))->not->toBe($build->id)
        ->and(PcBuild::where('user_id', $me->id)->count())->toBe(2)
        ->and($response->json('data.subtotal.amount'))->toBe(70000);
});

it('always shows the current price, so a price change after saving is reflected', function () {
    $me = customer();
    $build = savedBuild($me, ['cpu' => 50000]);
    $product = $build->items->first()->product;

    $product->update(['price' => Money::fromSen(45000)]);

    $this->actingAs($me)->getJson("/api/builds/{$build->id}")->assertJsonPath('data.subtotal.amount', 45000);
});

it('keeps a withdrawn part visible to its owner, flagged as unavailable and left out of the total', function () {
    $me = customer();
    $build = savedBuild($me, ['cpu' => 50000, 'ram' => 20000]);
    $cpu = $build->load('items.product')->items->firstWhere('slot', BuilderSlot::Cpu)->product;
    $cpu->update(['name' => 'Retired CPU']);
    $cpu->delete();

    $response = $this->actingAs($me)->getJson("/api/builds/{$build->id}")->assertOk();

    expect($response->json('data.items.0.product.name'))->toBe('Retired CPU')
        ->and($response->json('data.items.0.purchasable'))->toBeFalse()
        ->and($response->json('data.issues.0.code'))->toBe('product_unavailable')
        ->and($response->json('data.issues.0.message'))->toBe('Retired CPU is no longer available.')
        ->and($response->json('data.subtotal.amount'))->toBe(20000)
        ->and($response->json('data.purchasable'))->toBeFalse();
});

it('reflects stock changes in a saved build', function () {
    $me = customer();
    $build = savedBuild($me, ['cpu' => 50000]);
    Inventory::where('product_id', $build->items->first()->product_id)->update(['stock' => 0]);

    $this->actingAs($me)->getJson("/api/builds/{$build->id}")->assertJsonPath('data.issues.0.code', 'out_of_stock');
});

it('keeps a unique part per slot at the database level', function () {
    $build = savedBuild(customer(), ['cpu' => 50000]);

    expect(fn () => PcBuildItem::factory()->create(['pc_build_id' => $build->id, 'slot' => 'cpu']))->toThrow(QueryException::class);
});
