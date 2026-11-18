<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D28 costing rules: cost required on new products (0 = genuine zero), an existing cost
 * can't be cleared, a purchase never mixes unknown-cost stock, and profit never treats
 * an unknown cost as zero.
 */
class CostingRulesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected Warehouse $shelf;
    protected Supplier $supplier;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function product(?float $cost, int $stock = 0, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'tenant_id'  => $this->tenant->id,
            'sku'        => 'SKU-'.uniqid(),
            'price'      => 100,
            'cost_price' => $cost,
        ], $overrides));

        if ($stock > 0) {
            Inventory::factory()->create([
                'tenant_id'    => $this->tenant->id,
                'warehouse_id' => $this->shelf->id,
                'product_id'   => $product->id,
                'quantity'     => $stock,
            ]);
        }

        return $product;
    }

    private function createProduct(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/products', array_merge([
            'name'  => 'Rice 5kg',
            'sku'   => 'RICE-5',
            'price' => 120,
            'unit'  => 'pcs',
        ], $overrides));
    }

    private function updateProduct(Product $product, array $overrides = [])
    {
        return $this->actingAs($this->admin)->putJson("/api/products/{$product->id}", array_merge([
            'name'  => $product->name,
            'sku'   => $product->sku,
            'price' => $product->price,
            'unit'  => $product->unit,
        ], $overrides));
    }

    /** @param array<int, array{0: Product, 1: int, 2: float, 3?: string}> $lines [product, quantity, unit price, unit type] */
    private function buy(array $lines)
    {
        return $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items'       => array_map(fn ($l) => [
                'product_id'   => $l[0]->id,
                'quantity'     => $l[1],
                'unit_price'   => $l[2],
                'unit_type'    => $l[3] ?? 'base',
                'warehouse_id' => $this->shelf->id,
            ], $lines),
        ]);
    }

    private function sell(Product $product, int $quantity = 1)
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [['product_id' => $product->id, 'quantity' => $quantity]],
        ])->assertStatus(201);
    }

    private function costOf(Product $product): ?string
    {
        return DB::table('products')->where('id', $product->id)->value('cost_price');
    }

    // ─── R1 / R2: product forms ──────────────────────────────────────────────

    public function test_creating_a_product_without_a_cost_is_rejected(): void
    {
        $this->createProduct()->assertStatus(422)->assertJsonValidationErrors(['cost_price']);

        $this->assertDatabaseMissing('products', ['sku' => 'RICE-5']);
    }

    public function test_a_cost_of_zero_is_saved_as_a_genuine_zero(): void
    {
        $this->createProduct(['cost_price' => 0])->assertStatus(201);

        $this->assertSame('0.0000', DB::table('products')->where('sku', 'RICE-5')->value('cost_price'));
    }

    public function test_an_existing_cost_cannot_be_cleared(): void
    {
        $product = $this->product(50);

        $this->updateProduct($product, ['cost_price' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['cost_price']);

        $this->assertEquals(50, $this->costOf($product));
    }

    public function test_a_legacy_product_with_an_unknown_cost_can_be_edited_without_inventing_one(): void
    {
        $product = $this->product(null);

        $this->updateProduct($product, ['name' => 'Rice 5kg (new bag)'])->assertOk();

        $this->assertNull($this->costOf($product));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Rice 5kg (new bag)']);
    }

    public function test_an_existing_cost_can_be_changed(): void
    {
        $product = $this->product(50);

        $this->updateProduct($product, ['cost_price' => 60])->assertOk();

        $this->assertEquals(60, $this->costOf($product));
    }

    // ─── R4 / R5: weighted average ───────────────────────────────────────────

    public function test_first_purchase_onto_zero_stock_sets_the_average(): void
    {
        $product = $this->product(0);

        $this->buy([[$product, 10, 50]])->assertCreated();

        $this->assertEquals(50, $this->costOf($product));
    }

    public function test_first_purchase_of_a_legacy_unknown_cost_product_with_no_stock_sets_the_average(): void
    {
        $product = $this->product(null);

        $this->buy([[$product, 10, 50]])->assertCreated();

        $this->assertEquals(50, $this->costOf($product));
    }

    public function test_purchase_onto_known_cost_stock_averages(): void
    {
        $product = $this->product(50, stock: 10);

        $this->buy([[$product, 10, 70]])->assertCreated();

        $this->assertEquals(60, $this->costOf($product));
    }

    public function test_a_genuine_zero_cost_is_averaged_as_zero(): void
    {
        $product = $this->product(0, stock: 10);

        $this->buy([[$product, 10, 50]])->assertCreated();

        $this->assertEquals(25, $this->costOf($product));
    }

    public function test_a_box_purchase_sets_the_cost_per_piece(): void
    {
        $product = $this->product(0, overrides: ['secondary_unit' => 'box', 'conversion_factor' => 12]);

        $this->buy([[$product, 1, 600, 'secondary']])->assertCreated();

        $this->assertEquals(50, $this->costOf($product));
    }

    public function test_the_same_product_twice_in_one_purchase_averages_line_by_line(): void
    {
        $product = $this->product(50, stock: 10);

        // (10×50 + 10×70) ÷ 20 = 60, then (20×60 + 20×40) ÷ 40 = 50
        $this->buy([[$product, 10, 70], [$product, 20, 40]])->assertCreated();

        $this->assertEquals(50, $this->costOf($product));
    }

    // ─── R6: unknown-cost stock is never mixed ───────────────────────────────

    public function test_buying_a_product_whose_existing_stock_has_an_unknown_cost_is_refused(): void
    {
        $product = $this->product(null, stock: 10);

        $this->buy([[$product, 10, 50]])->assertStatus(422);

        $this->assertNull($this->costOf($product));
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertEquals(10, Inventory::where('product_id', $product->id)->sum('quantity'));
        $this->assertDatabaseMissing('ledger_entries', ['type' => 'PURCHASE_CHARGE']);
    }

    // ─── Sales ───────────────────────────────────────────────────────────────

    public function test_a_sale_does_not_change_the_average_cost(): void
    {
        $product = $this->product(50, stock: 10);

        $this->sell($product, 3);

        $this->assertEquals(50, $this->costOf($product));
    }

    // ─── 4b: profit never treats an unknown cost as zero ─────────────────────

    public function test_an_order_with_a_known_cost_shows_its_profit(): void
    {
        $product = $this->product(40, stock: 5);

        $orderId = $this->sell($product)->json('id');

        $this->actingAs($this->admin)->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('cost', 40)
            ->assertJsonPath('gross_profit', 60)
            ->assertJsonPath('unknown_cost_lines', 0);
    }

    public function test_an_order_with_an_unknown_cost_shows_profit_as_unavailable(): void
    {
        $product = $this->product(null, stock: 5);

        $orderId = $this->sell($product)->json('id');

        $this->actingAs($this->admin)->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('cost', null)
            ->assertJsonPath('gross_profit', null)
            ->assertJsonPath('unknown_cost_lines', 1);
    }

    public function test_the_report_excludes_unknown_cost_orders_from_profit_but_keeps_their_sales(): void
    {
        $known   = $this->product(40, stock: 5);
        $unknown = $this->product(null, stock: 5);

        $knownOrder   = $this->sell($known)->json('id');
        $unknownOrder = $this->sell($unknown)->json('id');

        $today  = LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString();
        $report = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$today}&to={$today}&print=1")
            ->assertOk();

        $report->assertJsonPath('summary.total_revenue', 200);
        $report->assertJsonPath('summary.gross_profit', 60);
        $report->assertJsonPath('summary.sales_excluded_from_profit', 100);
        $report->assertJsonPath('summary.orders_excluded_from_profit', 1);

        $rows = collect($report->json('profit_by_order.data'))->keyBy('invoice_number');
        $invoice = fn ($id) => DB::table('orders')->where('id', $id)->value('invoice_number');

        $this->assertEquals(60, $rows[$invoice($knownOrder)]['profit']);
        $this->assertNull($rows[$invoice($unknownOrder)]['profit']);
        $this->assertNull($rows[$invoice($unknownOrder)]['cost']);
    }
}
