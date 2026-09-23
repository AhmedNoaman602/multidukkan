<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Warehouse $warehouse;
    protected Product $product;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => null,
            'role' => 'tenant_admin',
        ]);
    }

    private function logMovement(string $type = 'ADJUSTMENT_IN', int $quantity = 5): InventoryTransaction
    {
        return InventoryTransaction::create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'type' => $type,
            'quantity' => $quantity,
        ]);
    }

    public function test_product_with_stock_history_but_zero_stock_cannot_be_deleted(): void
    {
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 0,
        ]);
        $this->logMovement();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/products/{$this->product->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory', 1);
    }

    public function test_product_without_any_history_can_be_deleted_and_clears_empty_stock_rows(): void
    {
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/products/{$this->product->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $this->product->id]);
        $this->assertDatabaseCount('inventory', 0);
    }

    public function test_warehouse_with_stock_history_but_zero_stock_cannot_be_deleted(): void
    {
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 0,
        ]);
        $this->logMovement();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/warehouses/{$this->warehouse->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouse->id]);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('inventory', 1);
    }

    public function test_warehouse_without_history_can_be_deleted(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/warehouses/{$this->warehouse->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('warehouses', ['id' => $this->warehouse->id]);
    }

    /**
     * Documents the behaviour ExpenseTest case C depends on: a deleted store's expenses
     * survive as tenant-level rows. Pinned here so the MUL2-11 FK change cannot flip it
     * to RESTRICT without a deliberate decision.
     */
    public function test_store_deletion_still_preserves_expenses_as_tenant_level(): void
    {
        Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->warehouse->delete();

        $expense = Expense::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/stores/{$this->store->id}");

        $response->assertOk();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'store_id' => null]);
    }

    public function test_supplier_with_payments_but_no_purchase_orders_cannot_be_deleted(): void
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        SupplierPayment::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'amount' => 250,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/suppliers/{$supplier->id}");

        $response->assertStatus(422);
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertNull($supplier->fresh()->deleted_at);
    }

    public function test_customer_with_zero_balance_ledger_history_cannot_be_deleted(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'type' => 'ORDER_CHARGE',
            'direction' => 'debit',
            'amount' => 100,
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'type' => 'PAYMENT',
            'direction' => 'credit',
            'amount' => -100,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/customers/{$customer->id}");

        $response->assertStatus(422);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertNull($customer->fresh()->deleted_at);
    }

    public function test_creating_inventory_through_api_writes_a_transaction(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/inventory', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 40,
            'threshold' => 5,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('inventory', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 40,
            'threshold' => 5,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'type' => 'ADJUSTMENT_IN',
            'quantity' => 40,
        ]);
    }

    public function test_product_opening_quantity_writes_a_transaction(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/products', [
            'name' => 'Opening Balance Widget',
            'sku' => 'OBW-001',
            'price' => 50,
            'opening_quantity' => 30,
            'stocks' => [
                ['warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'threshold' => 5],
            ],
        ]);

        $response->assertStatus(201);
        $productId = $response->json('data.id');

        $this->assertDatabaseHas('inventory', [
            'product_id' => $productId,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 30,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'product_id' => $productId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'ADJUSTMENT_IN',
            'quantity' => 30,
        ]);
    }

    public function test_purge_empty_stock_rows_refuses_when_history_exists(): void
    {
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 0,
        ]);
        $this->logMovement();

        $this->expectException(\RuntimeException::class);

        app(InventoryService::class)->purgeEmptyStockRows('product_id', $this->product->id);
    }

    public function test_purge_empty_stock_rows_refuses_when_quantity_is_not_zero(): void
    {
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 7,
        ]);

        $this->expectException(\RuntimeException::class);

        app(InventoryService::class)->purgeEmptyStockRows('product_id', $this->product->id);
    }
}
