<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderIndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private Supplier $supplier;
    private Product $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->supplier = Supplier::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Nile Textiles',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Cotton Roll',
        ]);

        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
    }

    private function purchaseOrder(array $overrides = [], int $quantity = 2, int $unitPrice = 150): PurchaseOrder
    {
        $purchaseOrder = PurchaseOrder::factory()->create(array_merge([
            'tenant_id'   => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'total'       => $quantity * $unitPrice,
        ], $overrides));

        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id'        => $this->product->id,
            'warehouse_id'      => $this->warehouse->id,
            'quantity'          => $quantity,
            'unit_price'        => $unitPrice,
            'total'             => $quantity * $unitPrice,
        ]);

        return $purchaseOrder;
    }

    public function test_index_returns_purchase_orders_with_items_and_supplier(): void
    {
        $purchaseOrder = $this->purchaseOrder();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/purchase-orders')
            ->assertStatus(200);

        $row = $response->json('data.0');

        $this->assertSame($purchaseOrder->id, $row['id']);
        $this->assertSame($purchaseOrder->invoice_number, $row['invoice_number']);
        $this->assertSame('Nile Textiles', $row['supplier_name']);
        $this->assertSame(300.0, (float) $row['total']);
        $this->assertSame(300.0, (float) $row['subtotal']);
        $this->assertSame(1, $row['items_count']);
        $this->assertSame('unpaid', $row['status']);
        $this->assertSame(300.0, (float) $row['amount_remaining']);

        $this->assertSame('Cotton Roll', $row['items'][0]['product_name']);
        $this->assertSame(2, (int) $row['items'][0]['quantity']);
        $this->assertSame(150.0, (float) $row['items'][0]['unit_price']);
    }

    public function test_index_reports_spend_and_payment_stats(): void
    {
        $paid = $this->purchaseOrder();
        $this->purchaseOrder();

        SupplierPayment::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $paid->id,
            'amount'            => 300,
        ]);

        $stats = $this->actingAs($this->admin)
            ->getJson('/api/purchase-orders')
            ->assertStatus(200)
            ->json('stats');

        $this->assertSame(2, $stats['total_orders']);
        $this->assertSame(600.0, (float) $stats['total_spent']);
        $this->assertSame(300.0, (float) $stats['paid_amount']);
        $this->assertSame(300.0, (float) $stats['unpaid_amount']);
    }

    public function test_index_derives_distinct_years_newest_first(): void
    {
        $this->purchaseOrder(['created_at' => '2024-03-04 09:00:00']);
        $this->purchaseOrder(['created_at' => '2026-01-09 09:00:00']);
        $this->purchaseOrder(['created_at' => '2026-07-22 09:00:00']);

        $years = $this->actingAs($this->admin)
            ->getJson('/api/purchase-orders')
            ->assertStatus(200)
            ->json('years');

        $this->assertSame([2026, 2024], $years);
    }

    public function test_index_paginates_and_reports_meta(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->purchaseOrder();
        }

        $response = $this->actingAs($this->admin)
            ->getJson('/api/purchase-orders')
            ->assertStatus(200);

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame(12, $response->json('meta.total'));
    }

    public function test_index_search_matches_supplier_name(): void
    {
        $this->purchaseOrder();

        $other = Supplier::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Delta Fabrics',
        ]);
        $this->purchaseOrder(['supplier_id' => $other->id]);

        $data = $this->actingAs($this->admin)
            ->getJson('/api/purchase-orders?search=Delta')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Delta Fabrics', $data[0]['supplier_name']);
    }

    public function test_index_excludes_other_tenants_purchase_orders(): void
    {
        $this->purchaseOrder();

        $otherTenant   = Tenant::factory()->create();
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $otherTenant->id]);
        PurchaseOrder::factory()->create([
            'tenant_id'   => $otherTenant->id,
            'supplier_id' => $otherSupplier->id,
            'total'       => 999,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/purchase-orders')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(300.0, (float) $response->json('stats.total_spent'));
    }
}
