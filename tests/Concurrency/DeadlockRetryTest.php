<?php

namespace Tests\Concurrency;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use Tests\TestCase;

/**
 * A deadlock rolls the whole transaction back, and Laravel can retry it. The retry
 * must start from the database again, not from PHP objects the failed attempt changed.
 *
 * Not RefreshDatabase: inside its wrapping transaction Laravel never retries a nested
 * one, so a retry could not be seen.
 */
class DeadlockRetryTest extends TestCase
{
    use DatabaseTruncation;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected User $user;
    protected Warehouse $shelf;
    protected Inventory $stock;
    protected Product $product;

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
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 60]);
        $this->stock   = Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    /** The next query matching $sqlStart fails once with MySQL's deadlock error. */
    private function deadlockOnce(string $sqlStart): void
    {
        $fired = false;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($sqlStart, &$fired) {
            if ($fired || !str_starts_with($query->sql, $sqlStart)) {
                return;
            }
            $fired = true;

            throw new QueryException($query->connectionName, $query->sql, $query->bindings, new PDOException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
            ));
        });
    }

    private function sell(int $quantity, bool $payNow = false): array
    {
        return $this->actingAs($this->user)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ], $payNow ? ['pay_immediately' => true, 'payment_method' => 'cash'] : []))
            ->assertStatus(201)->json();
    }

    public function test_every_connection_waits_at_most_10_seconds_for_a_lock(): void
    {
        $this->assertEquals(10, DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t);
    }

    public function test_sale_retried_after_a_deadlock_is_saved_once(): void
    {
        $this->deadlockOnce('insert into `ledger_entries`');

        $this->sell(1, payNow: true);

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertEquals(99, $this->stock->fresh()->quantity);
        $this->assertEquals(0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_direct_payment_retried_after_a_deadlock_is_saved_once(): void
    {
        $order = $this->sell(1);

        $this->deadlockOnce('insert into `ledger_entries`');

        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $order['id'],
            'customer_id' => $this->customer->id,
            'amount'      => 60,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertEquals(0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_auto_payment_retried_after_a_deadlock_is_saved_once(): void
    {
        $this->sell(1);

        $this->deadlockOnce('insert into `ledger_entries`');

        $this->actingAs($this->user)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id,
            'amount'      => 60,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertEquals(0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_cash_refund_retried_after_a_deadlock_is_recorded_once(): void
    {
        $order     = $this->sell(1, payNow: true);
        $paymentId = DB::table('payments')->where('order_id', $order['id'])->value('id');

        $this->deadlockOnce('insert into `ledger_entries`');

        $this->actingAs($this->user)->postJson("/api/customers/{$this->customer->id}/refund", [
            'amount'            => 20,
            'method'            => 'cash',
            'payment_id_target' => $paymentId,
        ])->assertSuccessful();

        $this->assertEquals(20, DB::table('payments')->where('id', $paymentId)->value('refunded_amount'));
        $this->assertSame(1, DB::table('ledger_entries')->where('type', 'REFUND')->count());
        $this->assertEquals(20, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }
}
