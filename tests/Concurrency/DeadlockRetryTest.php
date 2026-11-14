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

    /**
     * Line of 5 changed to 8. The deadlock hits after the item row was updated
     * (attempt 1 already set the PHP $item to 8), while the order total is re-charged.
     * A retry that trusts the in-memory $item computes 8 - 8 = 0 and takes no stock.
     */
    public function test_item_edit_retried_after_a_deadlock_takes_the_right_stock(): void
    {
        $order = $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 5]],
        ])->assertStatus(201)->json();
        $itemId = $order['items'][0]['id'];

        $this->deadlockOnce('update `ledger_entries`');

        $this->actingAs($this->user)
            ->patchJson("/api/orders/{$order['id']}/items/{$itemId}", ['quantity' => 8])
            ->assertOk()
            ->assertJsonPath('total', 480);

        $this->assertDatabaseHas('order_items', ['id' => $itemId, 'quantity' => 8]);
        $this->assertEquals(92, $this->stock->fresh()->quantity, 'Stock must drop by 3 more: 100 - 5 - 3.');
        $this->assertEquals(480, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }
}
