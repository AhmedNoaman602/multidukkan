<?php

// Runs money operations on its own MySQL connection for ConcurrencyTest, all inside
// one transaction:
//   1. the first step, then prints READY;
//   2. waits pause_seconds, then runs the remaining steps;
//   3. holds the transaction (and every lock it took) for hold_seconds, then commits.
//
// Usage: php tests/Concurrency/child.php <base64 json job>

use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$job = json_decode(base64_decode($argv[1]), true);

DB::statement('SET SESSION innodb_lock_wait_timeout = 10');

$user = User::findOrFail($job['user_id']);
Auth::setUser($user);

$run = fn (array $step) => match ($step['op']) {
    'sale'           => app(OrderService::class)->createOrder($step['data']),
    'auto_payment'   => app(PaymentService::class)->processAutoPayment($step['data'], $user),
    'direct_payment' => app(PaymentService::class)->processDirectPayment($step['data'], $user),
};

DB::beginTransaction();

try {
    $steps = $job['steps'];

    $run(array_shift($steps));
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);

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
