<?php

use App\Enums\BuilderSlot;
use App\Models\AuditLog;
use App\Models\CompatibilityRule;
use App\Models\User;
use App\Services\Compatibility\RuleRegistry;

function asRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('protects the rule settings: only admins with the permission may see or change them', function () {
    $this->getJson('/api/compatibility-rules')->assertUnauthorized();
    $this->actingAs(asRole('customer'))->getJson('/api/compatibility-rules')->assertForbidden();
    // Staff run the shop day to day but cannot change what counts as compatible.
    $this->actingAs(asRole('staff'))->getJson('/api/compatibility-rules')->assertForbidden();
    $this->actingAs(asRole('staff'))->putJson('/api/compatibility-rules/gpu_fits_case', ['is_enabled' => false])->assertForbidden();
    $this->actingAs(asRole('admin'))->getJson('/api/compatibility-rules')->assertOk();
});

it('lists every registered rule with its settings, even before the table is seeded', function () {
    $response = $this->actingAs(asRole('admin'))->getJson('/api/compatibility-rules')->assertOk();

    expect($response->json('data'))->toHaveCount(count(app(RuleRegistry::class)->all()));

    $psu = collect($response->json('data'))->firstWhere('key', 'psu_has_headroom');
    expect($psu)->toMatchArray(['name' => 'Power supply has headroom', 'is_enabled' => true, 'severity' => 'warning', 'default_severity' => 'warning'])
        ->and($psu['params'])->toBe(['base_system_watts' => 80, 'headroom_percent' => 30])
        ->and($psu['slots'])->toBe(['cpu', 'gpu', 'psu']);
});

it('changes severity and switches a rule off, and records who did it', function () {
    $admin = asRole('admin');

    $this->actingAs($admin)->putJson('/api/compatibility-rules/gpu_fits_case', ['severity' => 'warning', 'is_enabled' => false])
        ->assertOk()->assertJsonPath('data.severity', 'warning')->assertJsonPath('data.is_enabled', false);

    expect(CompatibilityRule::where('key', 'gpu_fits_case')->first()->only(['severity', 'is_enabled']))->toBe(['severity' => 'warning', 'is_enabled' => false]);

    $log = AuditLog::where('action', 'compatibility_rule.updated')->firstOrFail();
    expect($log->user_id)->toBe($admin->id)
        ->and($log->old_values)->toMatchArray(['rule' => 'gpu_fits_case', 'severity' => 'error', 'is_enabled' => true])
        ->and($log->new_values)->toMatchArray(['severity' => 'warning', 'is_enabled' => false]);
});

it('tunes numbers and keeps the other settings of the rule', function () {
    $admin = asRole('admin');

    $this->actingAs($admin)->putJson('/api/compatibility-rules/psu_has_headroom', ['params' => ['headroom_percent' => 50]])
        ->assertOk()->assertJsonPath('data.params', ['base_system_watts' => 80, 'headroom_percent' => 50]);
});

it('takes effect on the very next check of a build', function () {
    $admin = asRole('admin');
    $payload = ['items' => [
        buildItem(BuilderSlot::Gpu, specPart(BuilderSlot::Gpu, ['length_mm' => 400])),
        buildItem(BuilderSlot::Case_, specPart(BuilderSlot::Case_, ['gpu_max_length_mm' => 300])),
    ]];

    $this->postJson('/api/pc-builder/validate', $payload)->assertJsonPath('data.compatibility.status', 'incompatible');

    $this->actingAs($admin)->putJson('/api/compatibility-rules/gpu_fits_case', ['is_enabled' => false])->assertOk();
    $this->postJson('/api/pc-builder/validate', $payload)->assertJsonPath('data.compatibility.status', 'compatible');
});

it('validates changes', function (string $key, array $payload, string $field) {
    $this->actingAs(asRole('admin'))->putJson("/api/compatibility-rules/{$key}", $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'bad severity' => ['gpu_fits_case', ['severity' => 'fatal'], 'severity'],
    'enabled is not a boolean' => ['gpu_fits_case', ['is_enabled' => 'maybe'], 'is_enabled'],
    'unknown setting' => ['gpu_fits_case', ['params' => ['colour' => 1]], 'params.colour'],
    'headroom too large' => ['psu_has_headroom', ['params' => ['headroom_percent' => 500]], 'params.headroom_percent'],
    'headroom negative' => ['psu_has_headroom', ['params' => ['headroom_percent' => -1]], 'params.headroom_percent'],
    'allowance not a number' => ['psu_covers_estimated_load', ['params' => ['base_system_watts' => 'lots']], 'params.base_system_watts'],
]);

it('returns 404 for a rule that does not exist', function () {
    $this->actingAs(asRole('admin'))->putJson('/api/compatibility-rules/no_such_rule', ['is_enabled' => false])->assertNotFound();
});

it('does not write an audit entry when nothing changed', function () {
    $this->actingAs(asRole('admin'))->putJson('/api/compatibility-rules/gpu_fits_case', ['severity' => 'error', 'is_enabled' => true])->assertOk();

    expect(AuditLog::where('action', 'compatibility_rule.updated')->count())->toBe(0);
});
