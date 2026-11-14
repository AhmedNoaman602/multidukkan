<?php

namespace Tests\Concurrency;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two real MySQL connections: this test's own, and a child PHP process.
 * RefreshDatabase can't be used here: it wraps the test in one transaction
 * that the child's connection would never see.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected User $user;
    protected Warehouse $shelf;
    protected Product $productA;
    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 10');

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user     = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);

        // Two products, so the two sales never wait on the same inventory row.
        $this->productA = $this->stockedProduct();
        $this->productB = $this->stockedProduct();
    }

    protected function tearDown(): void
    {
        // Leave the database empty for the RefreshDatabase tests that run after this class.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function stockedProduct(): Product
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 60]);

        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $product->id,
            'quantity'     => 100,
        ]);

        return $product;
    }

    private function saleData(Product $product): array
    {
        return [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $product->id, 'quantity' => 1]],
        ];
    }

    /** Starts the child, waits until its first step ran inside its open transaction. */
    private function startChild(array $steps, int $pauseSeconds = 0, int $holdSeconds = 0): Process
    {
        $job = base64_encode(json_encode([
            'user_id'       => $this->user->id,
            'steps'         => $steps,
            'pause_seconds' => $pauseSeconds,
            'hold_seconds'  => $holdSeconds,
        ]));

        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/child.php'), $job],
            base_path(),
            [
                'APP_ENV'       => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'DB_DATABASE'   => DB::connection()->getDatabaseName(),
            ],
            null,
            60,
        );
        $process->start();

        $process->waitUntil(fn ($type, $output) => str_contains($output, 'READY'));
        $this->assertTrue($process->isRunning(), 'Child stopped before holding its transaction: '.$process->getErrorOutput());

        return $process;
    }

    private function finish(Process $child): void
    {
        $child->wait();
        $this->assertTrue($child->isSuccessful(), 'Child failed: '.$child->getErrorOutput());
    }

    /**
     * C5. Customer has 50 credit. Two sales of 60 at the same moment: the child's sale
     * has consumed the credit but not committed when this connection's sale runs.
     * Correct result: credit consumed once (50), balance 120 - 50 = 70.
     */
    public function test_two_simultaneous_sales_spend_the_credit_only_once(): void
    {
        $this->actingAs($this->user)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id,
            'amount'      => 50,
            'method'      => 'cash',
        ])->assertStatus(201);

        $child = $this->startChild([['op' => 'sale', 'data' => $this->saleData($this->productA)]], holdSeconds: 3);

        $this->actingAs($this->user)
            ->postJson('/api/orders', $this->saleData($this->productB))
            ->assertStatus(201);

        $this->finish($child);

        $consumed = DB::table('ledger_entries')
            ->where('customer_id', $this->customer->id)
            ->where('type', 'CREDIT_CONSUMED')
            ->sum('amount');

        $this->assertEquals(50, $consumed, 'Credit was spent more than once.');
        $this->assertSame(1, DB::table('payments')->where('method', 'credit')->count());
        $this->assertEquals(70, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    private function unpaidOrder(float $total, $createdAt): Order
    {
        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => $total,
            'created_at'  => $createdAt,
        ]);

        app(LedgerService::class)->chargeOrder([
            'tenant_id'      => $order->tenant_id,
            'customer_id'    => $order->customer_id,
            'store_id'       => $order->store_id,
            'order_id'       => $order->id,
            'amount'         => $total,
            'invoice_number' => $order->invoice_number,
            'user_id'        => $this->user->id,
        ]);

        return $order;
    }

    private function directPayment(Order $order, float $amount): array
    {
        return ['op' => 'direct_payment', 'data' => [
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => $amount,
            'method'      => 'cash',
        ]];
    }

    /**
     * Two unpaid invoices: A (older) and B (newer), 100 each.
     * The child locks B, then reaches for A — the lock order of a direct payment on B
     * whose excess spills over to the older A. Meanwhile an auto-payment locks oldest
     * first: A, then B. Each holds what the other wants.
     * Correct result: both succeed (one waits for the other), nothing surfaces to the client.
     */
    public function test_direct_and_auto_payment_for_one_customer_do_not_deadlock(): void
    {
        $orderA = $this->unpaidOrder(100, now()->subDay());
        $orderB = $this->unpaidOrder(100, now());

        $child = $this->startChild(
            [$this->directPayment($orderB, 50), $this->directPayment($orderA, 50)],
            pauseSeconds: 2,
        );

        $auto = $this->actingAs($this->user)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id,
            'amount'      => 200,
            'method'      => 'cash',
        ]);

        $child->wait();

        $this->assertTrue($child->isSuccessful(), 'Direct payments failed: '.$child->getErrorOutput());
        $auto->assertStatus(201);

        // 50 + 50 direct, then auto-payment pays the 50 left on each and keeps 100 as credit.
        $this->assertEquals(200, DB::table('payments')->sum('amount'));
        $this->assertEquals(100, DB::table('ledger_entries')->where('type', 'CREDIT_APPLY')->sum('amount'));
        $this->assertEquals(-100, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }
}
