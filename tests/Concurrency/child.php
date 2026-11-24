<?php

// Runs money operations on its own MySQL connection for ConcurrencyTest, all inside
// one transaction:
//   1. the first step, then creates ready_file;
//   2. waits pause_seconds, then runs the remaining steps;
//   3. holds the transaction (and every lock it took) for hold_seconds, then commits.
//
// Usage: php tests/Concurrency/child.php <base64 json job>

use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\LedgerService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$job = json_decode(base64_decode($argv[1]), true);

$user = User::findOrFail($job['user_id']);
Auth::setUser($user);

$run = fn (array $step) => match ($step['op']) {
    'sale'           => app(OrderService::class)->createOrder($step['data']),
    'auto_payment'   => app(PaymentService::class)->processAutoPayment($step['data'], $user),
    'direct_payment' => app(PaymentService::class)->processDirectPayment($step['data'], $user),
    'cancel'         => app(OrderService::class)->cancelOrder(Order::findOrFail($step['data']['order_id']), $user, $step['data']['settlement'] ?? null),
    'refund'         => app(LedgerService::class)->issueRefund($step['data'] + ['tenant_id' => $user->tenant_id, 'user_id' => $user->id]),
    'purchase'       => app(PurchaseOrderService::class)->createPurchaseOrder($step['data']),
    'lock_stock'     => app(InventoryService::class)->lockRows($step['data']['product_ids'], $step['data']['warehouse_ids']),
};

DB::beginTransaction();

try {
    $steps = $job['steps'];

    $run(array_shift($steps));
    // A file, not stdout: on Windows the parent can read stdout late, after this
    // process has already committed, and then nothing would run concurrently.
    file_put_contents($job['ready_file'], 'ready');
    fwrite(STDOUT, "READY\n");

    sleep($job['pause_seconds'] ?? 0);
    foreach ($steps as $step) {
        $run($step);
    }

    sleep($job['hold_seconds'] ?? 0);

    DB::commit();
    fwrite(STDOUT, "COMMITTED\n");
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
