<?php

namespace Tests\Concurrency;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
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

    /**
     * Two cancels of one unpaid order at the same moment (a double click). The child's
     * cancel has run but not committed when this one arrives; both passed the
     * controller's "already cancelled?" check before either transaction began.
     * Correct result: stock and charge are reversed once; the second cancel is refused.
     */
    public function test_two_simultaneous_cancels_reverse_the_order_only_once(): void
    {
        $orderId = $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->productA->id, 'quantity' => 5]],
        ])->assertStatus(201)->json('id');

        $child = $this->startChild([['op' => 'cancel', 'data' => ['order_id' => $orderId]]], holdSeconds: 3);

        $second = $this->actingAs($this->user)->deleteJson("/api/orders/{$orderId}");

        $this->finish($child);

        $stock = DB::table('inventory')->where('product_id', $this->productA->id)->value('quantity');
        $this->assertEquals(100, $stock, 'Stock must be restored once: 100 - 5 + 5.');
        $this->assertSame(1, DB::table('ledger_entries')->where('type', 'REVERSAL')->count());
        $this->assertEquals(0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));

        $second->assertStatus(422)->assertJsonPath('message', __('messages.order_already_cancelled'));
    }

    /**
     * Two purchases of 10 into the shelf (stock 100 → 120). A double click cancels the second
     * one: the child cancels it and holds its transaction while the same cancel arrives here.
     * The stock check can't stop the second cancel — 10 pieces of the first purchase are still
     * there. Correct result: 10 pieces leave once (110), the supplier charge is reversed once,
     * the second cancel is refused.
     */
    public function test_two_simultaneous_purchase_cancels_reverse_it_only_once(): void
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $purchase = fn () => $this->actingAs($this->user)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items'       => [[
                'product_id'   => $this->productA->id,
                'warehouse_id' => $this->shelf->id,
                'quantity'     => 10,
                'unit_price'   => 30,
            ]],
        ])->assertStatus(201)->json('data.id');

        $purchase();
        $secondId = $purchase();

        $child = $this->startChild([['op' => 'purchase_cancel', 'data' => ['purchase_order_id' => $secondId]]], holdSeconds: 3);

        $second = $this->actingAs($this->user)->deleteJson("/api/purchase-orders/{$secondId}");

        $this->finish($child);

        $stock = DB::table('inventory')->where('product_id', $this->productA->id)->value('quantity');
        $reversals = DB::table('ledger_entries')->where('type', 'PURCHASE_REVERSAL')->where('reference_id', $secondId)->count();

        $this->assertEquals(110, $stock, "Stock is {$stock}: the cancelled purchase's 10 pieces must leave once (second cancel answered {$second->status()}).");
        $this->assertSame(1, $reversals, 'The supplier charge was reversed '.$reversals.' times.');
        $this->assertEquals(300, app(LedgerService::class)->getSupplierBalance($this->tenant->id, $supplier->id), 'Only the first purchase is still owed.');

        $second->assertStatus(422)->assertJsonPath('message', __('messages.purchase_order_already_cancelled'));
    }

    private function purchaseOf(Supplier $supplier, int $quantity, float $unitPrice): int
    {
        return $this->actingAs($this->user)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items'       => [[
                'product_id'   => $this->productA->id,
                'warehouse_id' => $this->shelf->id,
                'quantity'     => $quantity,
                'unit_price'   => $unitPrice,
            ]],
        ])->assertStatus(201)->json('data.id');
    }

    /**
     * A purchase of 100, nothing paid. A double click on "pay 100 against this purchase":
     * the child pays and holds its transaction while the same payment arrives here.
     * Correct result: one payment of 100, the second refused; the supplier is owed 0.
     */
    public function test_two_simultaneous_payments_of_one_purchase_cannot_overpay_it(): void
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $poId     = $this->purchaseOf($supplier, 10, 10);
        $payment  = ['supplier_id' => $supplier->id, 'purchase_order_id' => $poId, 'amount' => 100, 'method' => 'cash'];

        $child  = $this->startChild([['op' => 'supplier_payment', 'data' => $payment]], holdSeconds: 3);
        $second = $this->actingAs($this->user)->postJson('/api/supplier-payments', $payment);
        $this->finish($child);

        $paid = (float) DB::table('supplier_payments')->where('purchase_order_id', $poId)->sum('amount');

        $this->assertEquals(100, $paid, "A purchase of 100 has {$paid} paid against it (second payment answered {$second->status()}).");
        $this->assertSame(1, DB::table('ledger_entries')->where('type', 'SUPPLIER_PAYMENT')->count());
        $this->assertEquals(0, app(LedgerService::class)->getSupplierBalance($this->tenant->id, $supplier->id));
        $second->assertStatus(422);
    }

    /**
     * Two purchases of 100 and 50 (owed 150). Two "pay the supplier 150" requests at once
     * (no purchase chosen, oldest first). Correct result: 150 paid once, the second refused.
     */
    public function test_two_simultaneous_supplier_payments_without_a_purchase_cannot_overpay(): void
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->purchaseOf($supplier, 10, 10);
        $this->purchaseOf($supplier, 5, 10);
        $payment = ['supplier_id' => $supplier->id, 'amount' => 150, 'method' => 'cash'];

        $child  = $this->startChild([['op' => 'supplier_payment', 'data' => $payment]], holdSeconds: 3);
        $second = $this->actingAs($this->user)->postJson('/api/supplier-payments', $payment);
        $this->finish($child);

        $paid = (float) DB::table('supplier_payments')->where('supplier_id', $supplier->id)->sum('amount');

        $this->assertEquals(150, $paid, "The supplier was owed 150 and {$paid} was paid (second payment answered {$second->status()}).");
        $this->assertEquals(0, app(LedgerService::class)->getSupplierBalance($this->tenant->id, $supplier->id));
        $second->assertStatus(422);
    }

    /**
     * A cash payment of 60. The child refunds 30 and holds its transaction; meanwhile the
     * payment is edited down to 40. adjustPayment checks "already refunded?" before its
     * transaction, so it reads 0 and lets the edit through. (40, not lower: below 30 the
     * database's refunded <= amount check would stop it — with a raw SQL 500.)
     * Correct result: the edit is refused (a refunded payment can't be edited); 60 stays.
     */
    public function test_editing_a_payment_while_it_is_being_refunded_is_refused(): void
    {
        $orderId = $this->actingAs($this->user)->postJson('/api/orders', $this->saleData($this->productA) + [
            'pay_immediately' => true,
            'payment_method'  => 'cash',
        ])->assertStatus(201)->json('id');
        $paymentId = DB::table('payments')->where('order_id', $orderId)->value('id');

        $child = $this->startChild([['op' => 'refund', 'data' => [
            'customer_id'       => $this->customer->id,
            'store_id'          => $this->store->id,
            'amount'            => 30,
            'method'            => 'cash',
            'payment_id_target' => $paymentId,
        ]]], holdSeconds: 3);

        $edit = $this->actingAs($this->user)->patchJson("/api/payments/{$paymentId}", [
            'amount' => 40,
            'method' => 'cash',
        ]);

        $this->finish($child);

        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $this->assertEquals(60, $payment->amount, 'A refunded payment must not be edited.');
        $this->assertEquals(30, $payment->refunded_amount);
        $this->assertEquals(60, DB::table('ledger_entries')
            ->where('reference_type', 'payment')->where('reference_id', $paymentId)
            ->where('type', 'PAYMENT')->value('amount'));

        $this->assertSame(422, $edit->status(), 'Edit response: '.$edit->getContent());
    }

    private function paidSale(Product $product, int $quantity = 1): int
    {
        return $this->actingAs($this->user)->postJson('/api/orders', array_merge($this->saleData($product), [
            'items'           => [['product_id' => $product->id, 'quantity' => $quantity]],
            'pay_immediately' => true,
            'payment_method'  => 'cash',
        ]))->assertStatus(201)->json('id');
    }

    /**
     * Invoice paid 60 in cash. The child cancels it as customer credit and holds; the cash
     * payment row still shows 60 unrefunded. A refund of that payment passes validation (the
     * invoice isn't cancelled yet) and then waits on the customer lock.
     * Correct result: the refund is refused once the cancel commits; the 60 is paid back once (as credit).
     */
    public function test_a_refund_waiting_on_a_cancel_to_credit_is_refused(): void
    {
        $orderId   = $this->paidSale($this->productA);
        $paymentId = DB::table('payments')->where('order_id', $orderId)->value('id');

        $child = $this->startChild([['op' => 'cancel', 'data' => ['order_id' => $orderId, 'settlement' => 'credit']]], holdSeconds: 3);

        $refund = $this->actingAs($this->user)->postJson("/api/customers/{$this->customer->id}/refund", [
            'payment_id_target' => $paymentId,
            'amount'            => 60,
            'method'            => 'cash',
        ]);

        $this->finish($child);

        $refund->assertStatus(422)->assertJsonPath('errors.payment_id_target.0', __('messages.refund_order_cancelled'));
        $this->assertEquals(0, DB::table('payments')->where('id', $paymentId)->value('refunded_amount'));
        $this->assertSame(0, DB::table('ledger_entries')->where('type', 'REFUND')->count());
        $this->assertEquals(-60, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    /**
     * Invoice paid 60 in cash. The child refunds 20 and holds; the cancel (cash) waits.
     * Correct result: the cancel refunds only the 40 left — 60 paid back in total, never 80.
     */
    public function test_a_cancel_waiting_on_a_refund_pays_back_only_the_rest(): void
    {
        $orderId = $this->paidSale($this->productA);

        $child = $this->startChild([['op' => 'refund', 'data' => [
            'customer_id' => $this->customer->id,
            'store_id'    => $this->store->id,
            'amount'      => 20,
            'method'      => 'cash',
            'order_id'    => $orderId,
        ]]], holdSeconds: 3);

        $cancel = $this->actingAs($this->user)->postJson("/api/orders/{$orderId}/cancel", ['settlement' => 'cash']);

        $this->finish($child);

        $cancel->assertOk();
        $this->assertEquals(60, DB::table('ledger_entries')->where('type', 'REFUND')->sum('amount'));
        $this->assertEquals(60, DB::table('payments')->where('order_id', $orderId)->value('refunded_amount'));
        $this->assertEquals(0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    /**
     * Invoice paid entirely by store credit. Two cash cancels at once.
     * Correct result: the credit is restored and paid out once; the second cancel is refused.
     */
    public function test_two_cash_cancels_pay_the_store_credit_out_once(): void
    {
        $this->actingAs($this->user)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id, 'amount' => 60, 'method' => 'cash',
        ])->assertStatus(201);
        $orderId = $this->actingAs($this->user)->postJson('/api/orders', $this->saleData($this->productA))
            ->assertStatus(201)->json('id');

        $child = $this->startChild([['op' => 'cancel', 'data' => ['order_id' => $orderId, 'settlement' => 'cash']]], holdSeconds: 3);

        $second = $this->actingAs($this->user)->postJson("/api/orders/{$orderId}/cancel", ['settlement' => 'cash']);

        $this->finish($child);

        $second->assertStatus(422)->assertJsonPath('message', __('messages.order_already_cancelled'));
        $this->assertSame(1, DB::table('ledger_entries')->where('type', 'CREDIT_PAYOUT')->count());
        $this->assertEquals(60, DB::table('ledger_entries')->where('type', 'CREDIT_PAYOUT')->sum('amount'));
        $this->assertEquals(0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    /**
     * Store credit 60 paid invoice A; invoice B (60) is owed. The child pays B in cash and
     * holds the customer lock. A cash cancel of A must wait and then read the balance after
     * that payment — reading it before would see B's debt and wrongly refuse the payout.
     * Correct result: the cancel waits, then pays the 60 credit out.
     */
    public function test_a_cash_cancel_reads_the_credit_after_a_payment_it_waited_for(): void
    {
        $this->actingAs($this->user)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id, 'amount' => 60, 'method' => 'cash',
        ])->assertStatus(201);
        $orderA = $this->actingAs($this->user)->postJson('/api/orders', $this->saleData($this->productA))
            ->assertStatus(201)->json('id');
        $orderB = Order::findOrFail($this->actingAs($this->user)->postJson('/api/orders', $this->saleData($this->productB))
            ->assertStatus(201)->json('id'));

        $child = $this->startChild([$this->directPayment($orderB, 60)], holdSeconds: 3);

        $cancel = $this->actingAs($this->user)->postJson("/api/orders/{$orderA}/cancel", ['settlement' => 'cash']);

        $this->finish($child);

        $cancel->assertOk();
        $this->assertEquals(60, DB::table('ledger_entries')->where('type', 'CREDIT_PAYOUT')->sum('amount'));
        $this->assertEquals(0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }
}
