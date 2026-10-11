<?php

use App\Models\PcBuild;
use App\Support\Money;

beforeEach(function () {
    $this->owner = shopper();
    $this->pc = compatiblePc();
    $this->build = savedPc($this->owner, $this->pc, 'Budget Beast');
});

function shareLink(PcBuild $build): string
{
    return test()->actingAs($build->user)->postJson("/api/builds/{$build->id}/share")->assertOk()->json('data.token');
}

it('is private until its owner asks for a link', function () {
    expect($this->build->fresh()->share_token)->toBeNull();

    $this->actingAs($this->owner)->getJson("/api/builds/{$this->build->id}")->assertOk()->assertJsonPath('data.share', null);
    $this->actingAs($this->owner)->getJson('/api/builds')->assertOk()->assertJsonPath('data.0.share', null);
});

it('gives the owner a link with a long random token instead of the build\'s id', function () {
    $response = $this->actingAs($this->owner)->postJson("/api/builds/{$this->build->id}/share")->assertOk();
    $token = $response->json('data.token');

    expect($token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($token)->not->toContain($this->build->id)
        ->and($response->json('data.path'))->toBe("/shared/{$token}")
        ->and($response->json('data.shared_at'))->not->toBeNull()
        ->and($this->build->fresh()->share_token)->toBe($token);

    // The owner sees the link wherever the build is shown.
    $this->actingAs($this->owner)->getJson("/api/builds/{$this->build->id}")->assertJsonPath('data.share.token', $token);
    $this->actingAs($this->owner)->getJson('/api/builds')->assertJsonPath('data.0.share.path', "/shared/{$token}");
});

it('keeps the same link when asked again, so one that was already sent keeps working', function () {
    $first = shareLink($this->build);

    expect(shareLink($this->build))->toBe($first);
});

it('shows a shared build to anyone, logged in or not, at the shop\'s current prices', function () {
    $token = shareLink($this->build);
    auth()->forgetGuards();
    $this->pc['cpu']->update(['price' => Money::fromSen(70000)]); // the price drops after sharing

    $response = $this->getJson("/api/shared-builds/{$token}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Budget Beast')
        ->assertJsonPath('data.complete', true)
        ->assertJsonPath('data.purchasable', true)
        ->assertJsonPath('data.compatibility.status', 'compatible');

    $cpu = collect($response->json('data.items'))->firstWhere('slot', 'cpu');

    expect(count($response->json('data.items')))->toBe(6)
        ->and($cpu['product']['name'])->toBe($this->pc['cpu']->name)
        ->and($cpu['unit_price']['amount'])->toBe(70000)
        ->and($response->json('data.subtotal.amount'))->toBe(70000 + 60000 + 30000 + 25000 + 40000 + 35000);

    $this->actingAs(shopper())->getJson("/api/shared-builds/{$token}")->assertOk();
});

it('does not say whose build it is, or give away anything that could find the private build', function () {
    $token = shareLink($this->build);
    auth()->forgetGuards();

    $body = $this->getJson("/api/shared-builds/{$token}")->assertOk()->getContent();

    expect($body)->not->toContain($this->build->id)
        ->not->toContain($this->owner->email)
        ->not->toContain($this->owner->name)
        ->not->toContain('user_id')
        ->not->toContain('"id":"'.$this->build->id);
    expect(array_keys(json_decode($body, true)['data']))->not->toContain('id', 'user', 'share');
});

it('does not name a part that the shop has since withdrawn', function () {
    $token = shareLink($this->build);
    $this->pc['storage']->update(['status' => 'draft', 'name' => 'Secret Unreleased Drive']);
    auth()->forgetGuards();

    $response = $this->getJson("/api/shared-builds/{$token}")->assertOk()->assertJsonPath('data.purchasable', false);

    expect($response->getContent())->not->toContain('Secret Unreleased Drive')
        ->and(collect($response->json('data.items'))->firstWhere('slot', 'storage')['product'])->toBeNull();
});

it('stops working the moment the owner stops sharing, and a new link is a different one', function () {
    $old = shareLink($this->build);

    $this->actingAs($this->owner)->deleteJson("/api/builds/{$this->build->id}/share")->assertNoContent();
    auth()->forgetGuards();
    $this->getJson("/api/shared-builds/{$old}")->assertNotFound();

    $new = shareLink($this->build);
    auth()->forgetGuards();

    expect($new)->not->toBe($old);
    $this->getJson("/api/shared-builds/{$old}")->assertNotFound(); // the old link stays dead
    $this->getJson("/api/shared-builds/{$new}")->assertOk();
});

it('follows the build: edits show up, and deleting the build kills the link', function () {
    $token = shareLink($this->build);
    $this->actingAs($this->owner)->putJson("/api/builds/{$this->build->id}", ['name' => 'Renamed Beast', 'items' => pcPayload($this->pc)['items']])->assertOk();
    auth()->forgetGuards();
    $this->getJson("/api/shared-builds/{$token}")->assertOk()->assertJsonPath('data.name', 'Renamed Beast');

    $this->actingAs($this->owner)->deleteJson("/api/builds/{$this->build->id}")->assertNoContent();
    auth()->forgetGuards();
    $this->getJson("/api/shared-builds/{$token}")->assertNotFound();
});

it('does not share a copy just because the original is shared', function () {
    shareLink($this->build);

    $copy = $this->actingAs($this->owner)->postJson("/api/builds/{$this->build->id}/duplicate")->assertCreated();

    expect($copy->json('data.share'))->toBeNull();
});

it('lets only the owner switch sharing on or off', function () {
    $other = shopper();

    $this->actingAs($other)->postJson("/api/builds/{$this->build->id}/share")->assertNotFound();
    expect($this->build->fresh()->share_token)->toBeNull();

    $token = shareLink($this->build);
    $this->actingAs($other)->deleteJson("/api/builds/{$this->build->id}/share")->assertNotFound();
    expect($this->build->fresh()->share_token)->toBe($token);

    auth()->forgetGuards();
    $this->postJson("/api/builds/{$this->build->id}/share")->assertUnauthorized();
    $this->deleteJson("/api/builds/{$this->build->id}/share")->assertUnauthorized();
});

it('answers "not found" for anything that is not exactly a live token', function (callable $token) {
    $live = shareLink($this->build);
    auth()->forgetGuards();

    $this->getJson('/api/shared-builds/'.$token($live, $this->build))->assertNotFound();
})->with([
    'a made-up token' => [fn () => str_repeat('a', 40)],
    'the build\'s own id' => [fn ($live, $build) => $build->id],
    'a token cut short' => [fn ($live) => substr($live, 0, 39)],
    'a token with something added' => [fn ($live) => $live.'x'],
    'the token in another case' => [fn ($live) => $live === strtolower($live) ? strtoupper($live) : strtolower($live)],
    'a database wildcard' => [fn () => str_repeat('%', 40)],
    'an underscore wildcard' => [fn () => str_repeat('_', 40)],
]);

it('cannot be switched on by saving a build with a token in the request', function () {
    $this->actingAs($this->owner)->putJson("/api/builds/{$this->build->id}", ['name' => 'Mine', 'share_token' => str_repeat('z', 40), 'items' => pcPayload($this->pc)['items']])->assertOk();

    expect($this->build->fresh()->share_token)->toBeNull();
});

it('gives every shared build its own token', function () {
    $tokens = collect(range(1, 5))->map(fn () => shareLink(savedPc($this->owner, $this->pc)));

    expect($tokens->unique())->toHaveCount(5);
});
