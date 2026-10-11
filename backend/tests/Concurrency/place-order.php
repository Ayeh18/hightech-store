<?php

/*
| One customer placing an order, as a process of its own. CheckoutRaceTest starts several of these at once, which is the
| only way to have checkouts truly overlap: inside one PHP process, code runs one line after another.
|
| Usage: php place-order.php <user id> <address id> <start at (unix time, with fraction)> [idempotency key]
| Prints one line of JSON: {"result": "placed" | "replayed" | "refused" | "error", ...}
*/

use App\Models\User;
use App\Services\Orders\CheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $addressId, $startAt] = $argv;
$idempotencyKey = $argv[4] ?? null;

// This script places real orders. It must never be pointed at anything but a test database.
if (! str_ends_with((string) DB::connection()->getDatabaseName(), '_testing')) {
    fwrite(STDERR, 'Refusing to run: "'.DB::connection()->getDatabaseName().'" is not a testing database.');
    exit(2);
}

$user = User::findOrFail((int) $userId);
DB::connection()->getPdo(); // connect now, so the wait below ends with everyone ready to go

// Everyone waits for the same moment, so the checkouts really do collide instead of arriving one after another.
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $placed = app(CheckoutService::class)->place($user, (int) $addressId, null, null, $idempotencyKey);
    echo json_encode(['result' => $placed->created ? 'placed' : 'replayed', 'order' => $placed->order->order_number]);
} catch (ValidationException $e) {
    echo json_encode(['result' => 'refused', 'message' => collect($e->errors())->flatten()->first()]);
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'message' => $e::class.': '.$e->getMessage()]);
}
