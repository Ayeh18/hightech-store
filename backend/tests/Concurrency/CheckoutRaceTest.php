<?php

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Orders\StockReservation;
use Illuminate\Database\QueryException;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/*
| What happens when customers act at the SAME moment. Other tests run inside one database transaction that is undone
| afterwards, which is fast but means nothing ever truly overlaps. These tests save real rows and use separate processes
| and connections, then empty the test database again (see tests/Pest.php).
*/

/** A customer with one unit of the product in their cart and somewhere to send it. Returns [user, address id]. */
function readyToBuy(Product $product): array
{
    $user = shopper();
    app(CartService::class)->addProduct($user, $product->id, 1);

    return [$user, Address::factory()->for($user)->create()->id];
}

/**
 * Runs one checkout per entry, each in its own PHP process, all released at the same instant.
 *
 * @param  array<int, array{0: User, 1: int, 2?: string}>  $checkouts  [user, address id, optional idempotency key]
 * @return Collection<int, array{result: string, order?: string, message?: string}>
 */
function checkOutTogether(array $checkouts): Collection
{
    $startAt = sprintf('%.4F', microtime(true) + 1.5); // long enough for every process to boot and connect

    $results = Process::pool(function (Pool $pool) use ($checkouts, $startAt) {
        foreach ($checkouts as $checkout) {
            $pool->path(base_path())->timeout(60)->command(array_filter([
                PHP_BINARY, 'tests/Concurrency/place-order.php', (string) $checkout[0]->id, (string) $checkout[1], $startAt, $checkout[2] ?? null,
            ]));
        }
    })->start()->wait();

    return $results->collect()->map(function ($process) {
        expect($process->exitCode())->toBe(0, $process->errorOutput());

        return json_decode($process->output(), true);
    });
}

it('sells the last units exactly once when many customers check out at the same moment', function () {
    $product = Product::factory()->priced(50000)->stock(3)->create(['name' => 'Last Three']);
    $customers = collect(range(1, 8))->map(fn () => readyToBuy($product))->all();

    $results = checkOutTogether($customers);

    // Three got an order; five were told, politely, that it is gone. Nobody crashed.
    expect($results->where('result', 'placed'))->toHaveCount(3)
        ->and($results->where('result', 'refused'))->toHaveCount(5)
        ->and($results->where('result', 'refused')->pluck('message')->unique()->all())->toBe(['Last Three is out of stock.'])
        ->and($results->where('result', 'error')->all())->toBe([]);

    // The books agree: three orders, three units held, none left over and nothing oversold.
    expect(Order::count())->toBe(3)
        ->and(inventoryOf($product)->reserved)->toBe(3)
        ->and(inventoryOf($product)->stock)->toBe(3)
        ->and(inventoryOf($product)->available)->toBe(0);

    // Order numbers are handed out one at a time too: no two orders share one.
    expect(Order::pluck('order_number')->unique())->toHaveCount(3)
        ->and($results->where('result', 'placed')->pluck('order')->sort()->values()->all())->toBe(Order::orderBy('order_number')->pluck('order_number')->all());
});

it('creates one order when the same customer\'s checkout arrives several times at once (a double click, a retry)', function () {
    $product = Product::factory()->priced(50000)->stock(10)->create();
    [$user, $addressId] = readyToBuy($product);

    $results = checkOutTogether(array_fill(0, 5, [$user, $addressId, 'same-click-0001']));

    expect($results->where('result', 'placed'))->toHaveCount(1)
        ->and($results->where('result', 'replayed'))->toHaveCount(4)
        ->and($results->pluck('order')->unique())->toHaveCount(1)
        ->and(Order::count())->toBe(1)
        ->and(inventoryOf($product)->reserved)->toBe(1);
});

it('creates one order when a customer submits the same cart several times at once without a key', function () {
    $product = Product::factory()->priced(50000)->stock(10)->create();
    [$user, $addressId] = readyToBuy($product);

    $results = checkOutTogether(array_fill(0, 4, [$user, $addressId]));

    // The first empties the cart; the others wait their turn and then find nothing left to order.
    expect($results->where('result', 'placed'))->toHaveCount(1)
        ->and($results->where('result', 'refused'))->toHaveCount(3)
        ->and($results->where('result', 'error')->all())->toBe([])
        ->and(Order::count())->toBe(1)
        ->and(inventoryOf($product)->reserved)->toBe(1);
});

it('makes a second checkout WAIT for the first, then shows it what is really left', function () {
    $product = Product::factory()->stock(1)->create(['name' => 'Only One']);
    config(['database.connections.rival' => config('database.connections.pgsql')]); // a second, separate connection
    $reserve = fn () => app(StockReservation::class)->reserve([$product->id => 1]);

    // Customer A is in the middle of checking out: the unit is taken, but nothing is saved for good yet.
    DB::connection('pgsql')->beginTransaction();
    $reserve();

    // Customer B arrives at that moment. B may not even READ the row until A is done; here B gives up waiting after
    // 300 ms. It matters that the read is what waits: a B allowed to read now would see "1 available", which is stale.
    DB::setDefaultConnection('rival');
    DB::beginTransaction();
    DB::statement("SET LOCAL lock_timeout = '300ms'");
    expect($reserve)->toThrow(function (QueryException $e) {
        expect($e->getCode())->toBe('55P03') // lock_not_available
            ->and($e->getSql())->toStartWith('select')->toContain('for update');
    });
    DB::rollBack();

    // A finishes.
    DB::setDefaultConnection('pgsql');
    DB::commit();

    // B tries again, and now sees the truth instead of the stale "1 available" that would have oversold it.
    DB::setDefaultConnection('rival');
    expect(fn () => DB::transaction($reserve))->toThrow(ValidationException::class, 'Only One is out of stock.');

    DB::setDefaultConnection('pgsql');
    DB::purge('rival');
    expect(inventoryOf($product)->reserved)->toBe(1)->and(inventoryOf($product)->available)->toBe(0);
});
