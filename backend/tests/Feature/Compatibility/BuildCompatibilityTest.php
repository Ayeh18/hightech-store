<?php

use App\Enums\BuilderSlot as Slot;
use App\Models\CompatibilityRule;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\User;
use App\Services\Compatibility\RuleRegistry;
use Database\Seeders\CompatibilityRuleSeeder;

/** Parts that all fit together: AM5 + DDR5, a roomy case, a strong PSU. */
function goodParts(): array
{
    return [
        Slot::Cpu->value => specPart(Slot::Cpu, ['socket' => 'AM5', 'tdp_watts' => 65, 'integrated_graphics' => 'Yes', 'cooler_included' => 'Yes']),
        Slot::Motherboard->value => specPart(Slot::Motherboard, ['socket' => 'AM5', 'ram_type' => 'DDR5', 'form_factor' => 'ATX', 'ram_slots' => 4, 'max_ram_gb' => 192, 'm2_slots' => 3]),
        Slot::Ram->value => specPart(Slot::Ram, ['ram_type' => 'DDR5', 'modules' => 2, 'capacity_gb' => 32]),
        Slot::Storage->value => specPart(Slot::Storage, ['form_factor' => 'M.2 2280']),
        Slot::Psu->value => specPart(Slot::Psu, ['wattage' => 750, 'form_factor' => 'ATX']),
        Slot::Case_->value => specPart(Slot::Case_, ['supported_form_factors' => ['ATX', 'Micro ATX'], 'gpu_max_length_mm' => 350, 'cpu_cooler_max_height_mm' => 170, 'psu_form_factor' => 'ATX']),
    ];
}

function selection(array $parts): array
{
    return ['items' => array_map(fn ($product, $slot) => buildItem(Slot::from($slot), $product), $parts, array_keys($parts))];
}

it('reports a fully compatible build, with the power numbers', function () {
    $this->postJson('/api/pc-builder/validate', selection(goodParts()))
        ->assertOk()
        ->assertJsonPath('data.compatibility.status', 'compatible')
        ->assertJsonPath('data.compatibility.issues', [])
        ->assertJsonPath('data.compatibility.metrics.estimated_power_watts', 145) // 65 + 80
        ->assertJsonPath('data.compatibility.metrics.recommended_psu_watts', 200) // 145 + 30% = 189 -> 200
        ->assertJsonPath('data.purchasable', true);
});

it('reports an incompatible build with a clear reason and blocks buying it', function () {
    $parts = goodParts();
    $parts['motherboard'] = specPart(Slot::Motherboard, ['socket' => 'AM4', 'ram_type' => 'DDR5', 'form_factor' => 'ATX']);

    $response = $this->postJson('/api/pc-builder/validate', selection($parts))->assertOk();

    expect($response->json('data.compatibility.status'))->toBe('incompatible')
        ->and($response->json('data.compatibility.issues.0'))->toBe([
            'rule' => 'cpu_socket_matches_motherboard',
            'severity' => 'error',
            'message' => 'CPU socket AM5 is not compatible with motherboard socket AM4.',
            'slots' => ['cpu', 'motherboard'],
        ])
        // The build is complete and in stock, but it must not be purchasable.
        ->and($response->json('data.complete'))->toBeTrue()
        ->and($response->json('data.purchasable'))->toBeFalse();
});

it('lists every problem at once', function () {
    $parts = goodParts();
    $parts['motherboard'] = specPart(Slot::Motherboard, ['socket' => 'AM4', 'ram_type' => 'DDR4', 'form_factor' => 'E-ATX']);

    $rules = collect($this->postJson('/api/pc-builder/validate', selection($parts))->json('data.compatibility.issues'))->pluck('rule')->all();

    expect($rules)->toContain('cpu_socket_matches_motherboard', 'ram_type_matches_motherboard', 'motherboard_fits_case');
});

it('shows warnings without blocking the purchase', function () {
    $parts = goodParts();
    $parts['psu'] = specPart(Slot::Psu, ['wattage' => 150, 'form_factor' => 'ATX']); // covers 145 W, but below the 200 W recommendation

    $response = $this->postJson('/api/pc-builder/validate', selection($parts))->assertOk();

    expect($response->json('data.compatibility.status'))->toBe('warning')
        ->and($response->json('data.compatibility.issues.0.rule'))->toBe('psu_has_headroom')
        ->and($response->json('data.compatibility.issues.0.severity'))->toBe('warning')
        ->and($response->json('data.purchasable'))->toBeTrue();
});

it('does not complain about parts that have no specs', function () {
    [$items] = completeBuild();

    $this->postJson('/api/pc-builder/validate', ['items' => $items])
        ->assertOk()->assertJsonPath('data.compatibility.status', 'compatible');
});

it('applies admin settings: a disabled rule is skipped and a changed severity is used', function () {
    $parts = goodParts();
    $parts['motherboard'] = specPart(Slot::Motherboard, ['socket' => 'AM4', 'ram_type' => 'DDR5', 'form_factor' => 'ATX']);
    $payload = selection($parts);

    CompatibilityRule::create(['key' => 'cpu_socket_matches_motherboard', 'is_enabled' => false, 'severity' => 'error']);
    $this->postJson('/api/pc-builder/validate', $payload)->assertJsonPath('data.compatibility.status', 'compatible');

    CompatibilityRule::where('key', 'cpu_socket_matches_motherboard')->update(['is_enabled' => true, 'severity' => 'warning']);
    $this->postJson('/api/pc-builder/validate', $payload)
        ->assertJsonPath('data.compatibility.status', 'warning')
        ->assertJsonPath('data.compatibility.issues.0.severity', 'warning')
        ->assertJsonPath('data.purchasable', true);
});

it('uses tuned numbers: a larger base allowance raises the estimate', function () {
    CompatibilityRule::create(['key' => 'psu_covers_estimated_load', 'is_enabled' => true, 'severity' => 'error', 'params' => ['base_system_watts' => 300]]);

    $this->postJson('/api/pc-builder/validate', selection(goodParts()))
        ->assertJsonPath('data.compatibility.metrics.estimated_power_watts', 365); // 65 + 300
});

it('does not check parts that must stay hidden (not on sale)', function () {
    $parts = goodParts();
    $hidden = specPart(Slot::Motherboard, ['socket' => 'AM4'], 10000, ['status' => 'draft']);
    $parts['motherboard'] = $hidden;

    $response = $this->postJson('/api/pc-builder/validate', selection($parts))->assertOk();

    // The draft board is reported as unavailable only; its AM4 socket must not leak through a compatibility message.
    expect($response->json('data.compatibility.issues'))->toBe([])
        ->and($response->getContent())->not->toContain('AM4');
});

it('includes the verdict in saved builds and "My builds"', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');
    $parts = goodParts();
    $parts['motherboard'] = specPart(Slot::Motherboard, ['socket' => 'AM4', 'ram_type' => 'DDR5', 'form_factor' => 'ATX']);

    $build = PcBuild::factory()->for($user)->create(['name' => 'Mismatch']);
    foreach ($parts as $slot => $product) {
        PcBuildItem::factory()->create(['pc_build_id' => $build->id, 'product_id' => $product->id, 'slot' => $slot]);
    }

    $this->actingAs($user)->getJson("/api/builds/{$build->id}")
        ->assertJsonPath('data.compatibility.status', 'incompatible')
        ->assertJsonPath('data.purchasable', false);

    $this->actingAs($user)->getJson('/api/builds')
        ->assertJsonPath('data.0.compatibility_status', 'incompatible')
        ->assertJsonPath('data.0.purchasable', false)
        ->assertJsonPath('data.0.issue_count', 1);
});

it('does not run extra queries per saved build in the list, even with compatibility checks', function () {
    $user = User::factory()->create();
    $user->assignRole('customer');

    $makeBuild = function () use ($user) {
        $build = PcBuild::factory()->for($user)->create();
        foreach (goodParts() as $slot => $product) {
            PcBuildItem::factory()->create(['pc_build_id' => $build->id, 'product_id' => $product->id, 'slot' => $slot]);
        }
    };

    $makeBuild();
    DB::enableQueryLog();
    $this->actingAs($user)->getJson('/api/builds')->assertOk();
    $withOne = count(DB::getQueryLog());

    foreach (range(1, 3) as $_) {
        $makeBuild();
    }

    DB::flushQueryLog();
    $this->actingAs($user)->getJson('/api/builds')->assertOk()->assertJsonCount(4, 'data');

    expect(count(DB::getQueryLog()))->toBe($withOne);
});

it('every spec key the rules read exists in the catalog definitions (spec contract)', function () {
    $categories = require database_path('seeders/data/catalog/categories.php');
    $keysBySlot = collect($categories)->filter(fn ($c) => $c['builder_slot'])->mapWithKeys(fn ($c) => [$c['builder_slot'] => collect($c['specs'])->pluck('key')->all()]);

    foreach (app(RuleRegistry::class)->all() as $rule) {
        foreach ($rule->specs() as $slot => $keys) {
            foreach ($keys as $key) {
                expect(in_array($key, $keysBySlot[$slot] ?? [], true))
                    ->toBeTrue("Rule {$rule->key()} reads \"{$key}\" for the {$slot} slot, but the catalog has no such spec.");
            }
        }
    }
});

it('has a unique key for every registered rule and a settings row for each after seeding', function () {
    $this->seed(CompatibilityRuleSeeder::class);
    $registry = app(RuleRegistry::class);

    expect(CompatibilityRule::count())->toBe(count($registry->all()))
        ->and(CompatibilityRule::pluck('key')->sort()->values()->all())->toBe(collect(array_keys($registry->all()))->sort()->values()->all());

    // Running it again never overwrites admin changes.
    CompatibilityRule::where('key', 'gpu_fits_case')->update(['severity' => 'warning', 'is_enabled' => false]);
    $this->seed(CompatibilityRuleSeeder::class);

    expect(CompatibilityRule::where('key', 'gpu_fits_case')->first()->only(['severity', 'is_enabled']))->toBe(['severity' => 'warning', 'is_enabled' => false]);
});
