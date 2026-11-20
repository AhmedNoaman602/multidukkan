<?php

namespace Tests\Concurrency;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * D31/P4 cost locking, on two real MySQL connections (this test + a child process, see
 * child.php). Lock order under test: every inventory row (L3, ascending id) before any
 * product row (L4, ascending id); whoever recalculates the average locks every inventory
 * row of the product. Each test also checks InnoDB's deadlock counter, because a deadlock
 * that a retry hides is still a deadlock.
 */
class CostLockingTest extends TestCase
{
    use DatabaseTruncation;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected Supplier $supplier;
    protected User $user;
    protected Warehouse $shelf;
    protected Warehouse $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user     = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->storage = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'type'      => Warehouse::TYPE_STORAGE,
        ]);
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** A product with stock on the shelf; products created earlier get lower inventory ids. */
    private function product(float $cost, int $shelfStock): Product
    {
        $product = Product::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'sku'        => 'SKU-'.uniqid(),
            'price'      => 60,
            'cost_price' => $cost,
        ]);

        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $product->id,
            'quantity'     => $shelfStock,
        ]);

        return $product;
    }

    private function emptyStorageRow(Product $product): void
    {
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->storage->id,
            'product_id'   => $product->id,
            'quantity'     => 0,
        ]);
    }

    /** @param array<int, array{0: Product, 1: int}> $lines [product, quantity] */
    private function saleData(array $lines): array
    {
        return [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
        ];
    }

    /** @param array<int, array{0: Product, 1: int, 2: float}> $lines [product, quantity, unit price] */
    private function purchaseData(array $lines, Warehouse $into): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'items'       => array_map(fn ($l) => [
                'product_id'   => $l[0]->id,
                'quantity'     => $l[1],
                'unit_price'   => $l[2],
                'unit_type'    => 'base',
                'warehouse_id' => $into->id,
            ], $lines),
        ];
    }

    private function costOf(Product $product): float
    {
        return (float) DB::table('products')->where('id', $product->id)->value('cost_price');
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('inventory')->where('product_id', $product->id)->sum('quantity');
    }

    private function deadlocks(): int
    {
        return (int) DB::selectOne(
            "SELECT `COUNT` AS c FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'"
        )->c;
    }

    /** The cost saved for the newest order line of this product (Stage 1 allocations). */
    private function allocationCostOf(Product $product): float
    {
        $itemId = DB::table('order_items')->where('product_id', $product->id)->max('id');

        return (float) DB::table('order_item_costs')->where('order_item_id', $itemId)->value('unit_cost');
    }

    /** Starts the child, waits until its first step ran inside its open transaction. */
    private function startChild(array $steps, int $pauseSeconds = 0, int $holdSeconds = 0): Process
    {
        $readyFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'concurrency-ready-'.uniqid();

        $job = base64_encode(json_encode([
            'user_id'       => $this->user->id,
            'steps'         => $steps,
            'pause_seconds' => $pauseSeconds,
            'hold_seconds'  => $holdSeconds,
            'ready_file'    => $readyFile,
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

        $deadline = microtime(true) + 30;
        while (!file_exists($readyFile) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
        @unlink($readyFile);

        $this->assertTrue($process->isRunning(), sprintf(
            "Child stopped before holding its transaction (exit %s).\nstdout: %s\nstderr: %s",
            var_export($process->getExitCode(), true), $process->getOutput(), $process->getErrorOutput()
        ));

        return $process;
    }

    private function finish(Process $child): void
    {
        $child->wait();
        $this->assertTrue($child->isSuccessful(), 'Child failed: '.$child->getErrorOutput());
    }

    // ─── 1. Two purchases of one product ─────────────────────────────────────

    /**
     * 10 @ 10 on the shelf. Child buys 10 @ 20 and holds; this connection buys 10 @ 40.
     * Either order gives (10×10 + 10×20 + 10×40) / 30 = 23.3333.
     * Passes today, but only by accident: both purchases pick the same next invoice number,
     * so the second waits on the purchase_orders unique key, gets a duplicate, and retries
     * from fresh data (same accidental protection as D24 for sales). Without that, both
     * would average from cost 10 / stock 10 and the second save would leave 25. The planned
     * locks make this explicit instead of relying on invoice numbers.
     */
    public function test_two_purchases_of_one_product_both_count_in_the_average(): void
    {
        $product   = $this->product(10, 10);
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([['op' => 'purchase', 'data' => $this->purchaseData([[$product, 10, 20]], $this->shelf)]], holdSeconds: 3);

        $this->actingAs($this->user)
            ->postJson('/api/purchase-orders', $this->purchaseData([[$product, 10, 40]], $this->shelf))
            ->assertStatus(201);

        $this->finish($child);

        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(30, $this->stockOf($product));
        $this->assertEquals(23.3333, $this->costOf($product), 'A concurrent purchase was lost from the average.');
    }

    // ─── 2. Sale and purchase of one product ─────────────────────────────────

    /**
     * 10 @ 10 on the shelf, empty storage row. Child sells 2 and holds; this connection buys
     * 10 @ 20 into storage. Two correct outcomes, one per serial order:
     *   sale first  → sale cost 10, average (8×10 + 10×20) / 18 = 15.5556
     *   purchase first → sale cost 15, average 15
     * The planned locks make the purchase wait for the sale (first outcome).
     * Today the purchase doesn't wait and averages over the 10 it saw before the sale.
     */
    public function test_a_purchase_during_a_sale_matches_one_serial_order(): void
    {
        $product = $this->product(10, 10);
        $this->emptyStorageRow($product);
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([['op' => 'sale', 'data' => $this->saleData([[$product, 2]])]], holdSeconds: 3);

        $this->actingAs($this->user)
            ->postJson('/api/purchase-orders', $this->purchaseData([[$product, 10, 20]], $this->storage))
            ->assertStatus(201);

        $this->finish($child);

        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(18, $this->stockOf($product));
        $this->assertSerialOrder($product, saleFirst: [10.0, 15.5556], purchaseFirst: [15.0, 15.0]);
    }

    /**
     * 10 @ 10 on the shelf, empty storage row. Child buys 10 @ 20 into storage and holds;
     * this connection sells 2. Correct: the same two serial outcomes as above. The planned
     * locks make the sale wait for the purchase (sale cost 15, average 15).
     */
    public function test_a_sale_during_a_purchase_matches_one_serial_order(): void
    {
        $product = $this->product(10, 10);
        $this->emptyStorageRow($product);
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([['op' => 'purchase', 'data' => $this->purchaseData([[$product, 10, 20]], $this->storage)]], holdSeconds: 3);

        $this->actingAs($this->user)
            ->postJson('/api/orders', $this->saleData([[$product, 2]]))
            ->assertStatus(201);

        $this->finish($child);

        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(18, $this->stockOf($product));
        $this->assertSerialOrder($product, saleFirst: [10.0, 15.5556], purchaseFirst: [15.0, 15.0]);
    }

    /** @param array{0: float, 1: float} $saleFirst [sale cost, average] */
    private function assertSerialOrder(Product $product, array $saleFirst, array $purchaseFirst): void
    {
        $actual = [$this->allocationCostOf($product), $this->costOf($product)];

        $this->assertContains($actual, [$saleFirst, $purchaseFirst], sprintf(
            'Sale cost %s with average %s matches no serial order.', $actual[0], $actual[1]
        ));
    }

    // ─── 3. A new stock row appears while a purchase recalculates ───────────────

    /**
     * The open question from the locking plan. 10 @ 10 on the shelf. Child buys 10 @ 20
     * and holds. Meanwhile 10 units are counted into a storage location that had no row
     * for this product yet (product stock editor → setStock → new row + ADJUSTMENT_IN).
     * The purchase can't lock a row that didn't exist when it locked. Correct = one serial
     * order: purchase first → average 15; stock-in first → (20×10 + 10×20) / 30 = 13.3333.
     * Expected to pass today and after Stage 1: a stock-in doesn't read or change the
     * average, so missing it is the same as it happening after the purchase.
     */
    public function test_stock_counted_into_a_new_location_during_a_purchase_matches_one_serial_order(): void
    {
        $product   = $this->product(10, 10);
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([['op' => 'purchase', 'data' => $this->purchaseData([[$product, 10, 20]], $this->shelf)]], holdSeconds: 3);

        app(InventoryService::class)->setStock($product->id, $this->storage->id, $this->tenant->id, 10, null, $this->user->id, 'count');

        $this->finish($child);

        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(30, $this->stockOf($product));
        $this->assertContains(round($this->costOf($product), 4), [15.0, 13.3333]);
    }

    /**
     * 10 @ 10 on the shelf. Child buys 10 @ 20 into the shelf and holds; this connection
     * buys 10 @ 40 into storage, creating that stock row. Correct either way: 23.3333.
     * Passes today through the invoice-number retry (see test 1). Planned: the second
     * purchase inserts its new row, waits on the shelf row the first one holds, then
     * re-reads cost and stock.
     */
    public function test_a_purchase_into_a_new_location_during_another_purchase_counts_both(): void
    {
        $product   = $this->product(10, 10);
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([['op' => 'purchase', 'data' => $this->purchaseData([[$product, 10, 20]], $this->shelf)]], holdSeconds: 3);

        $this->actingAs($this->user)
            ->postJson('/api/purchase-orders', $this->purchaseData([[$product, 10, 40]], $this->storage))
            ->assertStatus(201);

        $this->finish($child);

        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(30, $this->stockOf($product));
        $this->assertEquals(23.3333, $this->costOf($product));
    }

    // ─── 4. Several products: one lock order everywhere ─────────────────────────

    /**
     * P1's stock row has the lower id. The child is a transaction that locks P1, pauses,
     * then locks P2 (ascending, like every sale). This connection buys [P2, P1] in line order.
     * Today the cycle is on the product rows: the child's order line for P1 holds a shared
     * lock on product P1 (foreign-key check); the purchase's `UPDATE products` for P2 holds
     * an exclusive lock on P2, then its update of P1 waits for the child; the child's order
     * line for P2 needs a shared lock on P2 and waits for the purchase.
     * Planned: the purchase locks every stock row (P1 then P2) before inserting lines or
     * touching products, so it waits while holding nothing and the child finishes.
     */
    public function test_a_purchase_listing_products_out_of_id_order_does_not_deadlock_with_a_sale(): void
    {
        $p1 = $this->product(10, 100);
        $p2 = $this->product(10, 100);
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([
            ['op' => 'sale', 'data' => $this->saleData([[$p1, 1]])],
            ['op' => 'sale', 'data' => $this->saleData([[$p2, 1]])],
        ], pauseSeconds: 2);

        $purchase = $this->actingAs($this->user)
            ->postJson('/api/purchase-orders', $this->purchaseData([[$p2, 5, 10], [$p1, 5, 10]], $this->shelf));

        $child->wait();

        $this->assertTrue($child->isSuccessful(), 'Sales failed: '.$child->getErrorOutput());
        $this->assertSame(201, $purchase->status(), 'Purchase response: '.$purchase->getContent());
        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(104, $this->stockOf($p1));
        $this->assertSame(104, $this->stockOf($p2));
    }

    /**
     * Same shape with a cancel: the order's lines are [P2, P1]. Today cancel puts stock back
     * line by line (P2's row, then P1's) → deadlock with the child. A cancel retries, so the
     * request may still succeed — the deadlock counter shows it happened anyway.
     */
    public function test_cancelling_an_order_listing_products_out_of_id_order_does_not_deadlock_with_a_sale(): void
    {
        $p1 = $this->product(10, 100);
        $p2 = $this->product(10, 100);
        $orderId = $this->actingAs($this->user)
            ->postJson('/api/orders', $this->saleData([[$p2, 3], [$p1, 3]]))
            ->assertStatus(201)->json('id');
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([
            ['op' => 'sale', 'data' => $this->saleData([[$p1, 1]])],
            ['op' => 'sale', 'data' => $this->saleData([[$p2, 1]])],
        ], pauseSeconds: 2);

        $cancel = $this->actingAs($this->user)->deleteJson("/api/orders/{$orderId}");

        $child->wait();

        $this->assertTrue($child->isSuccessful(), 'Sales failed: '.$child->getErrorOutput());
        $this->assertSame(200, $cancel->status(), 'Cancel response: '.$cancel->getContent());
        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(99, $this->stockOf($p1));
        $this->assertSame(99, $this->stockOf($p2));
    }

    /** Regression guard: sales already lock in id order whatever the line order (green today). */
    public function test_two_sales_listing_products_in_opposite_order_do_not_deadlock(): void
    {
        $p1 = $this->product(10, 100);
        $p2 = $this->product(10, 100);
        $deadlocks = $this->deadlocks();

        $child = $this->startChild([['op' => 'sale', 'data' => $this->saleData([[$p2, 1], [$p1, 1]])]], holdSeconds: 2);

        $this->actingAs($this->user)
            ->postJson('/api/orders', $this->saleData([[$p1, 1], [$p2, 1]]))
            ->assertStatus(201);

        $this->finish($child);

        $this->assertSame($deadlocks, $this->deadlocks(), 'A deadlock happened.');
        $this->assertSame(98, $this->stockOf($p1));
        $this->assertSame(98, $this->stockOf($p2));
    }
}
