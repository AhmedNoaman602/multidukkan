<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// A purchase line's unit_price is per the line's own unit. Buying the same goods by the box (at the
// box price) or by the piece (at the piece price) must cost and owe exactly the same (MUL2-48).
class PurchaseUnitEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Warehouse $warehouse;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant    = Tenant::factory()->create();
        $this->store     = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->warehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->admin     = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    // 1 box = 1000 pieces; 500 pieces already in stock at 0.05 each.
    private function nails(): Product
    {
        $product = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'secondary_unit'    => 'box',
            'conversion_factor' => 1000,
            'cost_price'        => 0.05,
        ]);

        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $product->id,
            'quantity'     => 500,
        ]);

        return $product;
    }

    // Buys one line and returns everything the purchase changed.
    private function buy(Product $product, int $quantity, string $unitType, float $unitPrice): array
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);

        $po = $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items'       => [[
                'product_id'   => $product->id,
                'quantity'     => $quantity,
                'unit_type'    => $unitType,
                'warehouse_id' => $this->warehouse->id,
                'unit_price'   => $unitPrice,
            ]],
        ])->assertStatus(201)->json('data');

        return [
            'line_total'      => (float) DB::table('purchase_order_items')->where('purchase_order_id', $po['id'])->value('total'),
            'po_total'        => (float) PurchaseOrder::find($po['id'])->total,
            'purchase_charge' => (float) DB::table('ledger_entries')
                ->where('type', 'PURCHASE_CHARGE')
                ->where('reference_type', PurchaseOrder::class)
                ->where('reference_id', $po['id'])
                ->value('amount'),
            'supplier_owed'   => app(LedgerService::class)->getSupplierBalance($this->tenant->id, $supplier->id),
            'stock'           => (int) Inventory::where('product_id', $product->id)->sum('quantity'),
            'cost_price'      => (float) $product->fresh()->cost_price,
        ];
    }

    public function test_two_boxes_at_the_box_price_equal_2000_pieces_at_the_piece_price(): void
    {
        // Same goods: 2000 pieces at 0.10 each, bought as 2 boxes at 100.00 or as 2000 pieces at 0.10.
        $byBox   = $this->buy($this->nails(), 2, 'secondary', 100.00);
        $byPiece = $this->buy($this->nails(), 2000, 'base', 0.10);

        $this->assertEquals($byPiece, $byBox);
    }

    public function test_the_equal_result_is_the_correct_one(): void
    {
        // 2000 pieces at 0.10 = 200.00 owed; average cost (500 × 0.05 + 2000 × 0.10) / 2500 = 0.09
        $byBox = $this->buy($this->nails(), 2, 'secondary', 100.00);

        $this->assertEquals(200.0, $byBox['line_total']);
        $this->assertEquals(200.0, $byBox['po_total']);
        $this->assertEquals(200.0, $byBox['purchase_charge']);
        $this->assertEquals(200.0, $byBox['supplier_owed']);
        $this->assertEquals(2500, $byBox['stock']);
        $this->assertEquals(0.09, $byBox['cost_price']);
    }
}
