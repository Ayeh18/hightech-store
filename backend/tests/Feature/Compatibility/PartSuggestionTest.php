<?php

use App\Enums\BuilderSlot as Slot;
use App\Models\Product;

function parts(string $slot, array $context = [], string $extra = ''): string
{
    $query = ['slot' => $slot];

    foreach ($context as $contextSlot => $entry) {
        $query["parts[{$contextSlot}][id]"] = is_array($entry) ? $entry['id'] : $entry;

        if (is_array($entry) && isset($entry['qty'])) {
            $query["parts[{$contextSlot}][qty]"] = $entry['qty'];
        }
    }

    return '/api/pc-builder/parts?'.http_build_query($query).($extra ? "&{$extra}" : '');
}

function board(string $socket, int $sen = 10000, array $extra = []): Product
{
    return specPart(Slot::Motherboard, ['socket' => $socket, 'ram_type' => 'DDR5', 'form_factor' => 'ATX', ...$extra], $sen);
}

function ids($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

it('puts compatible motherboards first and hides incompatible ones by default', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);
    $am5 = board('AM5');
    board('AM4');
    board('LGA1700');

    $response = $this->getJson(parts('motherboard', ['cpu' => $cpu->id]))->assertOk();

    expect(ids($response))->toBe([$am5->id])
        ->and($response->json('data.0.compatibility.status'))->toBe('compatible')
        ->and($response->json('compatibility.hidden_incompatible'))->toBe(2)
        ->and($response->json('compatibility.counts'))->toBe(['compatible' => 1, 'incompatible' => 2])
        ->and($response->json('meta.total'))->toBe(1);
});

it('shows incompatible parts last, with the reason, when asked', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);
    $am4 = board('AM4', 1000);          // cheapest, but incompatible
    $am5 = board('AM5', 90000);         // expensive, compatible

    $response = $this->getJson(parts('motherboard', ['cpu' => $cpu->id], 'include_incompatible=1&sort=price_asc'))->assertOk();

    // Despite price_asc, the compatible board comes first.
    expect(ids($response))->toBe([$am5->id, $am4->id])
        ->and($response->json('data.1.compatibility.status'))->toBe('incompatible')
        ->and($response->json('data.1.compatibility.issues.0.message'))->toBe('CPU socket AM5 is not compatible with motherboard socket AM4.')
        ->and($response->json('compatibility.hidden_incompatible'))->toBe(0);
});

it('orders compatible, then warning, then incompatible, keeping the requested sort inside each group', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5', 'tdp_watts' => 170]);
    $weak = specPart(Slot::Cooler, ['supported_sockets' => ['AM5'], 'tdp_rating_watts' => 150], 1000);      // warning (rating < TDP)
    $okB = specPart(Slot::Cooler, ['supported_sockets' => ['AM5'], 'tdp_rating_watts' => 300], 30000);
    $okA = specPart(Slot::Cooler, ['supported_sockets' => ['AM5'], 'tdp_rating_watts' => 300], 20000);
    $wrongSocket = specPart(Slot::Cooler, ['supported_sockets' => ['AM4'], 'tdp_rating_watts' => 300], 500); // error

    $response = $this->getJson(parts('cooler', ['cpu' => $cpu->id], 'include_incompatible=1&sort=price_asc'))->assertOk();

    expect(ids($response))->toBe([$okA->id, $okB->id, $weak->id, $wrongSocket->id])
        ->and(collect($response->json('data'))->pluck('compatibility.status')->all())->toBe(['compatible', 'compatible', 'warning', 'incompatible']);
});

it('lists everything as compatible when nothing else is chosen yet', function () {
    board('AM5');
    board('AM4');

    $response = $this->getJson(parts('motherboard'))->assertOk()->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('compatibility.status')->unique()->all())->toBe(['compatible'])
        ->and($response->json('compatibility.hidden_incompatible'))->toBe(0);
});

it('judges only the candidate, not mismatches that already exist between other parts', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);
    $oldBoard = board('AM4', 10000, ['ram_type' => 'DDR4']); // the user already has a CPU/board mismatch
    $ddr4 = specPart(Slot::Ram, ['ram_type' => 'DDR4', 'modules' => 2]);
    $ddr5 = specPart(Slot::Ram, ['ram_type' => 'DDR5', 'modules' => 2]);

    $response = $this->getJson(parts('ram', ['cpu' => $cpu->id, 'motherboard' => $oldBoard->id], 'include_incompatible=1'))->assertOk();

    // DDR4 RAM is fine with the DDR4 board; the CPU/board problem is not blamed on the RAM.
    $byId = collect($response->json('data'))->keyBy('id');
    expect($byId[$ddr4->id]['compatibility']['status'])->toBe('compatible')
        ->and($byId[$ddr5->id]['compatibility']['status'])->toBe('incompatible');
});

it('ignores the slot being replaced: choosing a new motherboard is judged against the other parts only', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);
    $currentBoard = board('AM4');
    $newBoard = board('AM5');

    $response = $this->getJson(parts('motherboard', ['cpu' => $cpu->id, 'motherboard' => $currentBoard->id]))->assertOk();

    expect(ids($response))->toBe([$newBoard->id]);
});

it('uses the selected quantity for RAM when counting memory slots', function () {
    $board = board('AM5', 10000, ['ram_slots' => 2]);
    $kit = specPart(Slot::Ram, ['ram_type' => 'DDR5', 'modules' => 2, 'capacity_gb' => 32]);

    $one = $this->getJson(parts('ram', ['motherboard' => $board->id], 'include_incompatible=1'))->json('data.0.compatibility.status');
    $two = $this->getJson(parts('ram', ['motherboard' => $board->id, 'ram' => ['id' => 999999, 'qty' => 2]], 'include_incompatible=1'))->json('data.0.compatibility.status');

    expect($one)->toBe('compatible')->and($two)->toBe('incompatible'); // 2 kits x 2 sticks = 4 sticks in 2 slots
    expect($kit->id)->toBeInt();
});

it('does not blame a CPU for missing parts that are simply not chosen yet', function () {
    $board = board('AM5');
    specPart(Slot::Cpu, ['socket' => 'AM5', 'integrated_graphics' => 'No', 'cooler_included' => 'No']);

    $response = $this->getJson(parts('cpu', ['motherboard' => $board->id]))->assertOk();

    // "No integrated graphics / no cooler" warnings only make sense for a whole build.
    expect($response->json('data.0.compatibility.status'))->toBe('compatible');
});

it('ignores chosen parts that are in the wrong slot or not on sale', function () {
    $gpu = specPart(Slot::Gpu, ['length_mm' => 400]);
    $draftBoard = specPart(Slot::Motherboard, ['socket' => 'AM4'], 10000, ['status' => 'draft']);
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);

    // A GPU claimed to be the motherboard, and a draft motherboard: neither may influence the result.
    $response = $this->getJson(parts('cpu', ['motherboard' => $gpu->id], 'include_incompatible=1'))->assertOk();
    expect($response->json('data.0.compatibility.status'))->toBe('compatible');

    $response = $this->getJson(parts('cpu', ['motherboard' => $draftBoard->id], 'include_incompatible=1'))->assertOk();
    expect($response->json('data.0.compatibility.status'))->toBe('compatible')->and(ids($response))->toBe([$cpu->id]);
});

it('still applies search, brand, price and stock filters', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);
    $match = board('AM5', 20000);
    $match->update(['name' => 'Unique Needle Board']);
    board('AM5', 20000);                       // does not match the search
    $expensive = board('AM5', 90000);
    $expensive->update(['name' => 'Unique Needle Deluxe']);

    $ctx = ['cpu' => $cpu->id];

    expect(ids($this->getJson(parts('motherboard', $ctx, 'q=needle&price_max=50000'))))->toBe([$match->id])
        ->and(ids($this->getJson(parts('motherboard', $ctx, 'q=needle&sort=price_desc'))))->toBe([$expensive->id, $match->id]);
});

it('paginates the ranked list', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);
    foreach (range(1, 5) as $i) {
        board('AM5', $i * 1000);
    }

    $first = $this->getJson(parts('motherboard', ['cpu' => $cpu->id], 'per_page=2&sort=price_asc'))->assertOk();
    $third = $this->getJson(parts('motherboard', ['cpu' => $cpu->id], 'per_page=2&sort=price_asc&page=3'))->assertOk();

    expect($first->json('meta.total'))->toBe(5)
        ->and($first->json('meta.last_page'))->toBe(3)
        ->and($first->json('data'))->toHaveCount(2)
        ->and($third->json('data'))->toHaveCount(1)
        ->and($third->json('meta.current_page'))->toBe(3);
});

it('does not query per candidate (no N+1)', function () {
    $cpu = specPart(Slot::Cpu, ['socket' => 'AM5']);
    foreach (range(1, 3) as $_) {
        board('AM5');
    }

    DB::enableQueryLog();
    $this->getJson(parts('motherboard', ['cpu' => $cpu->id]))->assertOk();
    $withThree = count(DB::getQueryLog());

    foreach (range(1, 9) as $_) {
        board(['AM5', 'AM4'][array_rand(['AM5', 'AM4'])]);
    }

    DB::flushQueryLog();
    $this->getJson(parts('motherboard', ['cpu' => $cpu->id], 'per_page=24'))->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withThree);
});

it('validates the request', function (string $query, string $field) {
    $this->getJson("/api/pc-builder/parts?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing slot' => ['', 'slot'],
    'unknown slot' => ['slot=toaster', 'slot'],
    'unknown slot in parts' => ['slot=cpu&parts[toaster][id]=1', 'parts.toaster'],
    'part without id' => ['slot=cpu&parts[ram][qty]=2', 'parts.ram.id'],
    'bad quantity' => ['slot=cpu&parts[ram][id]=1&parts[ram][qty]=99', 'parts.ram.qty'],
]);

it('returns an empty list when the slot has no active category', function () {
    $this->getJson(parts('gpu'))->assertOk()->assertJsonCount(0, 'data');
});
