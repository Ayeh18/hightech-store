<?php

use App\Enums\BuilderSlot;
use App\Models\BuildRecommendation;
use App\Models\Inventory;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| The AI recommender is asked first when it is switched on, and nothing it says is believed until the shop has checked
| it. These tests play the AI service (no request leaves the computer) and make it answer well, badly and not at all.
| Whatever it does, the customer must get a valid build at the shop's own prices.
*/

beforeEach(function () {
    $this->parts = miniCatalog();
    config(['recommender.driver' => 'ai', 'recommender.ai.api_key' => 'sk-test-secret-key', 'recommender.ai.model' => 'test-model', 'recommender.ai.base_url' => 'https://ai.test/v1']);
    Http::preventStrayRequests();
});

/** A sensible gaming build from the small catalog, as an AI would send it: slot => product. */
function aiBuild(array $parts, array $changes = []): array
{
    return [...[
        'cpu' => $parts['cpu_am5'], 'motherboard' => $parts['mb_am5'], 'ram' => $parts['ram_ddr5_32'], 'gpu' => $parts['gpu_small'],
        'storage' => $parts['ssd_1000'], 'psu' => $parts['psu_750'], 'case' => $parts['case_big'],
    ], ...$changes];
}

/** Makes the AI service answer with this build (or with any raw text). */
function aiAnswers(array|string $build, string $summary = 'A balanced gaming PC.', array $extra = []): void
{
    $content = is_string($build) ? $build : json_encode([
        'parts' => array_values(array_map(
            fn (string $slot, $product) => ['slot' => $slot, 'id' => $product instanceof Product ? $product->id : $product, 'reason' => "Chosen for {$slot}.", ...($extra[$slot] ?? [])],
            array_keys($build), $build,
        )),
        'summary' => $summary,
    ]);

    Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]])]);
}

function recommend(array $body = [])
{
    return test()->postJson('/api/pc-builder/recommendations', ['budget' => 600000, 'purpose' => 'gaming', ...$body]);
}

/** The customer still gets a valid build from the shop's own rules, and is told the AI was not used. */
function expectFallback($response, string $because): void
{
    $response->assertOk()
        ->assertJsonPath('data.source', 'rules')
        ->assertJsonPath('data.build.purchasable', true)
        ->assertJsonPath('data.build.complete', true);

    expect(implode(' ', $response->json('data.notes')))->toContain('The AI suggestion could not be used this time')
        ->and($response->json('data.build.subtotal.amount'))->toBeLessThanOrEqual(600000)
        ->and(BuildRecommendation::findOrFail($response->json('data.id'))->fallback_reason)->toContain($because);
}

it('uses the AI\'s build when it passes every check, with the AI\'s reasons and the shop\'s prices', function () {
    aiAnswers(aiBuild($this->parts), 'Great for 1080p gaming.');

    $response = recommend()->assertOk()->assertJsonPath('data.source', 'ai')->assertJsonPath('data.build.purchasable', true);
    $items = collect($response->json('data.build.items'))->keyBy('slot');

    expect($items['gpu']['product']['name'])->toBe('Card 8GB')
        ->and($items['gpu']['unit_price']['amount'])->toBe(130000)
        ->and($response->json('data.build.subtotal.amount'))->toBe(80000 + 60000 + 45000 + 130000 + 28000 + 40000 + 30000)
        ->and(collect($response->json('data.reasons'))->firstWhere('slot', 'gpu')['reason'])->toBe('Chosen for gpu.')
        ->and($response->json('data.notes'))->toBe(['Great for 1080p gaming.'])
        ->and(BuildRecommendation::findOrFail($response->json('data.id')))->source->toBe('ai')->fallback_reason->toBeNull();
});

it('asks in the agreed format, with the key in a header and nothing about the customer', function () {
    aiAnswers(aiBuild($this->parts));
    $user = shopper();

    $this->actingAs($user)->postJson('/api/pc-builder/recommendations', ['budget' => 600000, 'purpose' => 'gaming', 'resolution' => '1440p', 'games' => ['Valorant']])->assertOk();

    Http::assertSent(function (Request $request) use ($user) {
        $body = $request->data();
        $sent = json_encode($body);
        $question = json_decode($body['messages'][1]['content'], true);

        return $request->url() === 'https://ai.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test-secret-key')
            && $body['model'] === 'test-model'
            && $body['response_format'] === ['type' => 'json_object']
            && $question['brief'] === ['budget_rm' => 6000, 'purpose' => 'Gaming', 'screen' => '1440p (QHD)', 'priority' => 'Balanced', 'games' => ['Valorant']]
            && array_keys($question['products']) === BuilderSlot::values()
            && in_array($this->parts['cpu_am5']->id, array_column($question['products']['cpu'], 'id'), true)
            && ! str_contains($sent, 'sk-test-secret-key')     // the key travels in the header only
            && ! str_contains($sent, $user->email)             // nothing that identifies the customer
            && ! str_contains($sent, $user->name);
    });
});

it('keeps the question short: only the specifications that matter, and a handful of products per slot', function () {
    config(['recommender.ai.candidates_per_slot' => 2]);
    aiAnswers(aiBuild($this->parts));

    recommend()->assertOk();

    Http::assertSent(function (Request $request) {
        $products = json_decode($request->data()['messages'][1]['content'], true)['products'];

        return array_keys($products['ram'][0]['specs']) === ['ram_type', 'capacity_gb', 'modules', 'speed_mhz'] // no cas_latency
            && array_keys($products['ram'][0]) === ['id', 'name', 'price_rm', 'specs']
            && count($products['ram']) === 2                      // of the three kits, the cheapest and the dearest
            && array_column($products['ram'], 'price_rm') === [170, 450]
            && count($products['cpu']) === 2;
    });
});

it('never shows the AI\'s key or its raw answer to the customer', function () {
    aiAnswers(aiBuild($this->parts));

    expect(recommend()->getContent())->not->toContain('sk-test-secret-key')->not->toContain('test-model')->not->toContain('ai.test');
});

it('ignores a product the AI made up', function () {
    aiAnswers(aiBuild($this->parts, ['gpu' => 999999]));

    expectFallback(recommend(), 'It chose product #999999 for "gpu", which is not one of the products it was offered');
});

it('ignores a real product put in the wrong slot', function () {
    aiAnswers(aiBuild($this->parts, ['gpu' => $this->parts['psu_750']]));

    expectFallback(recommend(), 'is not one of the products it was offered for that slot');
});

it('ignores a product that is out of stock or no longer on sale', function () {
    Inventory::where('product_id', $this->parts['gpu_small']->id)->update(['stock' => 0]);
    aiAnswers(aiBuild($this->parts));

    expectFallback(recommend(), 'is not one of the products it was offered');
});

it('ignores a price the AI states: only the shop\'s prices count', function () {
    // The AI claims the dear card costs RM 1; the real build would be RM 8,330, over the RM 6,000 budget.
    aiAnswers(aiBuild($this->parts, ['gpu' => $this->parts['gpu_big']]), extra: ['gpu' => ['price_rm' => 1, 'price' => 100]]);

    expectFallback(recommend(), 'which is over the budget of RM 6,000.00');
});

it('ignores parts that do not fit together', function (array $changes, string $because) {
    aiAnswers(aiBuild($this->parts, array_map(fn (string $name) => $this->parts[$name], $changes)));

    expectFallback(recommend(), $because);
})->with([
    'an AM5 processor on an LGA1700 board' => [['motherboard' => 'mb_lga', 'ram' => 'ram_ddr4_16'], 'Its parts do not work together'],
    'DDR4 memory on a DDR5 board' => [['ram' => 'ram_ddr4_16'], 'Its parts do not work together'],
    'an ATX board in a case that is too small' => [['case' => 'case_small'], 'Its parts do not work together'],
]);

it('ignores a PC that could not be used', function () {
    // A processor without built-in graphics, and no graphics card: nothing to plug a screen into.
    $build = aiBuild($this->parts, ['cpu' => $this->parts['cpu_lga'], 'motherboard' => $this->parts['mb_lga'], 'ram' => $this->parts['ram_ddr4_16'], 'case' => $this->parts['case_big']]);
    unset($build['gpu']);
    aiAnswers($build);

    expectFallback(recommend(), 'The PC would not be usable');
});

it('ignores a build with a part missing', function () {
    $build = aiBuild($this->parts);
    unset($build['storage']);
    aiAnswers($build);

    expectFallback(recommend(), 'It left out a part every PC needs: Storage');
});

it('ignores an answer it cannot read', function (string $answer, string $because) {
    aiAnswers($answer);

    expectFallback(recommend(), $because);
})->with([
    'prose instead of data' => ['Sure! I would pick a Ryzen and a nice graphics card.', 'was not the structured data'],
    'data in another shape' => ['{"build": {"cpu": 1}}', 'listed no parts'],
    'an empty list' => ['{"parts": []}', 'listed no parts'],
    'a slot that does not exist' => ['{"parts": [{"slot": "monitor", "id": 1}]}', 'could not be read'],
    'a product id that is not a number' => ['{"parts": [{"slot": "cpu", "id": "the best one"}]}', 'could not be read'],
    'the same slot twice' => ['{"parts": [{"slot": "cpu", "id": 1}, {"slot": "cpu", "id": 2}]}', 'could not be read'],
]);

it('falls back when the AI service is down, slow or refuses', function (callable $failure, string $because) {
    Http::fake(['ai.test/*' => $failure]);

    expectFallback(recommend(), $because);
})->with([
    'a server error' => [fn () => Http::response(['error' => 'boom'], 500), 'answered with an error (500)'],
    'too many requests' => [fn () => Http::response(['error' => 'rate limit'], 429), 'answered with an error (429)'],
    'a wrong key' => [fn () => Http::response(['error' => 'unauthorized'], 401), 'answered with an error (401)'],
    'no connection or a timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'could not be reached'],
    'an answer with nothing in it' => [fn () => Http::response(['choices' => []]), 'sent back an empty answer'],
]);

it('keeps the reason for a fallback in the shop\'s records and its log, never in what the customer sees', function () {
    Log::spy();
    Http::fake(['ai.test/*' => Http::response(['error' => 'invalid api key sk-test-secret-key'], 401)]);

    $response = recommend();

    expect($response->getContent())->not->toContain('401')->not->toContain('sk-test-secret-key');
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'The AI recommendation was not used.' && ! str_contains(json_encode($context), 'sk-test-secret-key'));
});

it('cleans up the AI\'s words before a customer reads them', function () {
    aiAnswers(aiBuild($this->parts), summary: "  <img src=x onerror=alert(1)>Solid\n\tchoice.  ", extra: ['cpu' => ['reason' => '<b>Fast</b> '.str_repeat('very ', 80).'fast.']]);

    $response = recommend()->assertOk()->assertJsonPath('data.source', 'ai');
    $reason = collect($response->json('data.reasons'))->firstWhere('slot', 'cpu')['reason'];

    expect($response->json('data.notes'))->toBe(['Solid choice.'])
        ->and($reason)->toStartWith('Fast very very')->not->toContain('<')
        ->and(mb_strlen($reason))->toBeLessThanOrEqual(240)
        ->and($reason)->toEndWith('…');
});

it('does not ask twice for the same brief while the catalog and prices are the same', function () {
    aiAnswers(aiBuild($this->parts));

    recommend()->assertJsonPath('data.source', 'ai');
    recommend()->assertJsonPath('data.source', 'ai');
    Http::assertSentCount(1);

    recommend(['budget' => 650000])->assertJsonPath('data.source', 'ai'); // a different brief is a new question
    Http::assertSentCount(2);

    $this->parts['gpu_small']->update(['price' => Money::fromSen(120000)]); // a price changed: the old answer may no longer be right
    recommend()->assertJsonPath('data.source', 'ai');
    Http::assertSentCount(3);
});

it('stops asking once the day\'s allowance is used, and starts again the next day', function () {
    config(['recommender.ai.daily_limit' => 2, 'recommender.ai.cache_minutes' => 0]);
    aiAnswers(aiBuild($this->parts));

    recommend(['budget' => 600000])->assertJsonPath('data.source', 'ai');
    recommend(['budget' => 610000])->assertJsonPath('data.source', 'ai');
    expectFallback(recommend(['budget' => 620000]), "Today's allowance of 2 AI requests is used up");
    Http::assertSentCount(2);

    $this->travel(1)->days();
    recommend(['budget' => 630000])->assertJsonPath('data.source', 'ai');
    Http::assertSentCount(3);
});

it('does not call anyone when the AI is switched on but has no key or no model', function (string $missing) {
    config(["recommender.ai.{$missing}" => null]);

    expectFallback(recommend(), 'The AI service is not set up');
    Http::assertNothingSent();
})->with(['api_key', 'model']);

it('never calls an AI service when the shop is set to use its own rules', function () {
    config(['recommender.driver' => 'rules']);

    $response = recommend()->assertOk()->assertJsonPath('data.source', 'rules');

    Http::assertNothingSent();
    expect(implode(' ', $response->json('data.notes')))->not->toContain('AI')
        ->and(BuildRecommendation::findOrFail($response->json('data.id'))->fallback_reason)->toBeNull();
});

it('still refuses a budget that is too low, without asking the AI to try', function () {
    aiAnswers(aiBuild($this->parts)); // RM 4,130: over a RM 1,500 budget

    recommend(['budget' => 150000])->assertUnprocessable()->assertJsonPath('errors.budget.0', fn (string $message) => str_contains($message, 'RM 2,300.00'));
});
