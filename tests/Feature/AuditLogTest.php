<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected Product $product;
    protected User $user;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
            'name'      => 'Ahmed',
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    public function test_stock_deduction_from_a_sale_records_the_acting_user(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseHas('inventory_transactions', [
            'type'       => 'SALE',
            'product_id' => $this->product->id,
            'user_id'    => $this->user->id,
        ]);
    }

    public function test_audit_log_resolves_user_name_and_description_for_a_sale(): void
    {
        $order = $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json();

        $response = $this->actingAs($this->user)
            ->getJson('/api/audit-log?source=inventory')
            ->assertStatus(200);

        $saleRow = collect($response->json('data'))->firstWhere('type', 'SALE');

        $this->assertNotNull($saleRow, 'Expected a SALE row in the audit log.');
        $this->assertEquals('Ahmed', $saleRow['user_name']);
        $this->assertNotNull($saleRow['description']);
        $this->assertStringContainsString($this->product->name, $saleRow['description']);
        $this->assertStringContainsString($order['invoice_number'], $saleRow['description']);
    }

    public function test_same_second_events_still_order_newest_first_via_microsecond_precision(): void
    {
        $older = LedgerEntry::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'type'           => 'CREDIT_APPLY',
            'amount'         => 10,
            'description'    => 'older event',
            'reference_type' => 'manual',
            'reference_id'   => $this->customer->id,
        ]);
        $older->created_at = Carbon::parse('2026-01-01 12:00:00.100000');
        $older->save();

        $newer = LedgerEntry::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'type'           => 'CREDIT_APPLY',
            'amount'         => 20,
            'description'    => 'newer event',
            'reference_type' => 'manual',
            'reference_id'   => $this->customer->id,
        ]);
        $newer->created_at = Carbon::parse('2026-01-01 12:00:00.900000');
        $newer->save();

        // Same whole second — this is the exact tie the old (second-precision) columns couldn't break.
        $this->assertEquals(
            $older->created_at->format('Y-m-d H:i:s'),
            $newer->created_at->format('Y-m-d H:i:s')
        );

        $response = $this->actingAs($this->user)
            ->getJson('/api/audit-log?source=ledger')
            ->assertStatus(200);

        $descriptions = collect($response->json('data'))->pluck('description')->all();

        $this->assertLessThan(
            array_search('older event', $descriptions),
            array_search('newer event', $descriptions),
            'The newer event should be listed before the older one.'
        );
    }

    public function test_multi_product_order_groups_into_one_batched_activity_row(): void
    {
        $secondProduct = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 50]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $secondProduct->id,
            'quantity'     => 100,
        ]);

        $order = $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
                ['product_id' => $secondProduct->id, 'quantity' => 3, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json();

        // The underlying data stays one row per product — needed for accurate stock history.
        $this->assertDatabaseCount('inventory_transactions', 2);
        $batchId = InventoryTransaction::first()->batch_id;
        $this->assertNotNull($batchId);
        $this->assertDatabaseHas('inventory_transactions', ['batch_id' => $batchId, 'product_id' => $this->product->id]);
        $this->assertDatabaseHas('inventory_transactions', ['batch_id' => $batchId, 'product_id' => $secondProduct->id]);

        // But the activity feed shows ONE grouped SALE row for the whole order, not two.
        $response = $this->actingAs($this->user)
            ->getJson('/api/audit-log?source=inventory')
            ->assertStatus(200);

        $saleRows = collect($response->json('data'))->where('type', 'SALE');
        $this->assertCount(1, $saleRows);

        $saleRow = $saleRows->first();
        $this->assertEquals(5, $saleRow['quantity']); // 2 + 3
        $this->assertEquals(2, $saleRow['item_count']);
        $this->assertStringContainsString('2 products', $saleRow['description']);
        $this->assertStringContainsString($order['invoice_number'], $saleRow['description']);
        $this->assertNotNull($saleRow['batch_id']);

        // Drawer detail endpoint returns the itemized breakdown for that batch.
        $detail = $this->actingAs($this->user)
            ->getJson("/api/audit-log/inventory-batches/{$saleRow['batch_id']}")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(2, $detail);
        $this->assertEqualsCanonicalizing(
            [$this->product->name, $secondProduct->name],
            collect($detail)->pluck('product_name')->all()
        );
    }

    public function test_creating_an_order_writes_no_updated_audit_entry(): void
    {
        // Regression: order creation is a two-step insert-then-fill flow (Order::create then
        // ->update(['total']) + a ->save() for the custom order_date). Those in-creation writes
        // must NOT surface as phantom "updated" activity rows — the ORDER_CHARGE ledger entry
        // already owns the creation moment.
        $order = $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json();

        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => Order::class,
            'auditable_id'   => $order['id'],
            'action'         => 'updated',
        ]);
    }

    public function test_editing_an_order_still_writes_an_updated_audit_entry(): void
    {
        $order = $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json();

        // A genuine post-creation edit loads a fresh model (wasRecentlyCreated = false), so it
        // must still be logged.
        $this->actingAs($this->user)
            ->patchJson("/api/orders/{$order['id']}", ['notes' => 'Edited note'])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Order::class,
            'auditable_id'   => $order['id'],
            'action'         => 'updated',
        ]);
    }
}
