<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W7 (ADR-011): prices and costs keep 4 decimals; order totals, purchase totals, payments and
 * the ledger are rounded to piasters, and the dropped fraction never becomes a credit or a debt.
 */
class PricePrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Supplier $supplier;
    private Product $nails;
    private User $admin;
    private Warehouse $shelf;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'price_tier' => 'default']);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->nails    = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'price'             => 0.0485,
            'cost_price'        => null,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 1000,
        ]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->inventory = Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $this->nails->id,
            'quantity'     => 5000,
        ]);
    }

    private function createOrder(array $items, array $extra = []): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => array_map(fn ($item) => $item + ['product_id' => $this->nails->id], $items),
        ] + $extra)->assertStatus(201)->json('id');

        return Order::findOrFail($id);
    }

    private function buy(int $quantity, float $unitPrice, string $unitType = 'secondary')
    {
        return $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items'       => [[
                'product_id'   => $this->nails->id,
                'quantity'     => $quantity,
                'unit_type'    => $unitType,
                'warehouse_id' => $this->shelf->id,
                'unit_price'   => $unitPrice,
            ]],
        ]);
    }

    private function balance(): float
    {
        return app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id);
    }

    private function supplierBalance(): float
    {
        return app(LedgerService::class)->getSupplierBalance($this->tenant->id, $this->supplier->id);
    }

    public function test_a_four_decimal_price_is_kept_on_the_line_and_the_total_is_rounded_to_piasters(): void
    {
        $order = $this->createOrder([['quantity' => 3, 'unit_price' => 1.2345]]);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 3, 'unit_price' => 1.2345]);
        $this->assertSame('3.70', $order->total);
        $this->assertDatabaseHas('ledger_entries', ['customer_id' => $this->customer->id, 'type' => 'ORDER_CHARGE', 'amount' => 3.70]);
        $this->assertEquals(3.70, $this->balance());
    }

    public function test_paying_the_rounded_total_settles_the_order_with_no_leftover_credit_or_debt(): void
    {
        $order = $this->createOrder([['quantity' => 3, 'unit_price' => 1.2345]]);

        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 3.70,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertTrue($order->fresh()->isSettled());
        $this->assertEquals(0, $this->balance());
        $this->assertEquals(0, app(LedgerService::class)->getCreditBalance($this->tenant->id, $this->customer->id));
        $this->assertDatabaseMissing('ledger_entries', ['customer_id' => $this->customer->id, 'type' => 'CREDIT_APPLY']);
    }

    public function test_the_total_rounds_to_the_nearest_piaster(): void
    {
        $order = $this->createOrder([['quantity' => 1, 'unit_price' => 3.7055]]);

        $this->assertSame('3.71', $order->total);
        $this->assertEquals(3.71, $this->balance());
    }

    public function test_a_four_decimal_product_price_prices_a_sale_by_the_piece_and_by_the_box(): void
    {
        $order = $this->createOrder([
            ['quantity' => 3],
            ['quantity' => 1, 'unit_type' => 'secondary'],
        ]);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'unit_type' => 'base', 'unit_price' => 0.0485]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'unit_type' => 'secondary', 'unit_price' => 48.50]);
        $this->assertSame('48.65', $order->total);
        $this->assertEquals(4997 - 1000, $this->inventory->fresh()->quantity);
    }

    public function test_add_item_merges_only_lines_with_the_same_four_decimal_price(): void
    {
        $order = $this->createOrder([['quantity' => 2, 'unit_price' => 1.2345]]);

        $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/items", [
            'product_id' => $this->nails->id, 'quantity' => 1, 'unit_price' => 1.2345,
        ])->assertSuccessful();
        $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/items", [
            'product_id' => $this->nails->id, 'quantity' => 1, 'unit_price' => 1.2346,
        ])->assertSuccessful();

        $this->assertEquals(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 3, 'unit_price' => 1.2345]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 1, 'unit_price' => 1.2346]);
        $this->assertSame('4.94', $order->fresh()->total);
        $this->assertEquals(4.94, $this->balance());
    }

    public function test_a_price_with_five_decimals_is_rejected_and_nothing_is_saved(): void
    {
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->nails->id, 'quantity' => 1, 'unit_price' => 1.23456]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.unit_price');

        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(5000, $this->inventory->fresh()->quantity);
    }

    public function test_payments_still_accept_only_piasters(): void
    {
        $order = $this->createOrder([['quantity' => 3, 'unit_price' => 1.2345]]);

        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 3.7035,
            'method'      => 'cash',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(3.70, $this->balance());
    }

    public function test_product_prices_and_cost_save_four_decimals(): void
    {
        $this->actingAs($this->admin)->putJson("/api/products/{$this->nails->id}", [
            'name'       => $this->nails->name,
            'sku'        => 'NAILS-1',
            'price'      => 0.0525,
            'price_a'    => 0.0499,
            'cost_price' => 0.0485,
        ])->assertOk();

        $this->assertDatabaseHas('products', ['id' => $this->nails->id, 'price' => 0.0525, 'price_a' => 0.0499, 'cost_price' => 0.0485]);
    }

    public function test_a_purchase_keeps_the_four_decimal_piece_cost_and_rounds_the_supplier_charge(): void
    {
        $this->inventory->update(['quantity' => 0]);

        $this->buy(2, 48.50)->assertCreated();

        $this->assertDatabaseHas('products', ['id' => $this->nails->id, 'cost_price' => 0.0485]);
        $this->assertDatabaseHas('supplier_products', ['supplier_id' => $this->supplier->id, 'product_id' => $this->nails->id, 'last_purchase_price' => 0.0485]);
        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $this->supplier->id, 'total' => 97.00]);
        $this->assertEquals(97.00, $this->supplierBalance());
    }

    public function test_the_weighted_average_cost_is_kept_to_four_decimals(): void
    {
        $this->inventory->update(['quantity' => 0]);

        $this->buy(2, 48.50)->assertCreated();
        $this->buy(1, 52.00)->assertCreated();

        // (2000 × 0.0485 + 1000 × 0.052) / 3000 = 0.049666… → 0.0497. With 2 decimals it was 0.05.
        $this->assertDatabaseHas('products', ['id' => $this->nails->id, 'cost_price' => 0.0497]);
        $this->assertEquals(149.00, $this->supplierBalance());
    }

    public function test_a_very_cheap_piece_cost_no_longer_rounds_to_zero(): void
    {
        $this->inventory->update(['quantity' => 0]);

        $this->buy(1, 4.00)->assertCreated();

        $this->assertDatabaseHas('products', ['id' => $this->nails->id, 'cost_price' => 0.0040]);
    }

    public function test_a_purchase_line_with_a_four_decimal_price_rounds_its_total_to_piasters(): void
    {
        $this->buy(3, 1.2345, 'base')->assertCreated();

        $this->assertDatabaseHas('purchase_order_items', ['product_id' => $this->nails->id, 'unit_price' => 1.2345, 'total' => 3.70]);
        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $this->supplier->id, 'total' => 3.70]);
        $this->assertEquals(3.70, $this->supplierBalance());
    }

    public function test_creating_and_recalculating_an_order_agree_on_a_total_that_lands_on_half_a_piaster(): void
    {
        // 4.1022 × 3 + 3.8014 × 6 = 35.1150 exactly; summed as floats it is 35.11499…, which PHP 8.4 rounded to 35.11.
        $order = $this->createOrder([
            ['quantity' => 3, 'unit_price' => 4.1022],
            ['quantity' => 6, 'unit_price' => 3.8014],
        ]);

        $this->assertSame('35.12', $order->total);
        $this->assertEquals(35.12, $this->balance());
        $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}")
            ->assertOk()->assertJsonPath('subtotal', 35.12);

        $this->actingAs($this->admin)->patchJson("/api/orders/{$order->id}", ['discount' => 0])->assertOk();

        $this->assertSame('35.12', $order->fresh()->total);
        $this->assertEquals(35.12, $this->balance());
    }

    public function test_a_percent_discount_is_rounded_to_piasters_before_it_is_subtracted(): void
    {
        // 1.4114 × 12.5% = 0.176425 → 0.18; 1.4114 − 0.18 = 1.2314 → 1.23.
        // Subtracting the unrounded 0.176425 would give 1.234975, which rounds twice to 1.24.
        $order = $this->createOrder([['quantity' => 1, 'unit_price' => 1.4114]], ['discount' => 12.5, 'discount_type' => 'percent']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'discount' => 0.18, 'total' => 1.23]);
        $this->assertEquals(1.23, $this->balance());

        $this->actingAs($this->admin)->patchJson("/api/orders/{$order->id}", ['discount' => 12.5, 'discount_type' => 'percent'])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'discount' => 0.18, 'total' => 1.23]);
        $this->assertEquals(1.23, $this->balance());
    }

    public function test_a_percent_discount_on_a_float_summed_subtotal_matches_the_policy(): void
    {
        // 8.0247 × 4 + 4.6689 × 8 = 69.45, summed as floats 69.44999…; 10% = 6.945 → 6.95, not PHP 8.4's 6.94.
        $order = $this->createOrder([
            ['quantity' => 4, 'unit_price' => 8.0247],
            ['quantity' => 8, 'unit_price' => 4.6689],
        ], ['discount' => 10, 'discount_type' => 'percent']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'discount' => 6.95, 'total' => 62.50]);

        $this->actingAs($this->admin)->patchJson("/api/orders/{$order->id}", ['discount' => 10, 'discount_type' => 'percent'])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'discount' => 6.95, 'total' => 62.50]);
        $this->assertEquals(62.50, $this->balance());
    }

    public function test_a_fixed_discount_that_leaves_half_a_piaster_rounds_up(): void
    {
        // 2.885 − 0.92 = 1.965, but as floats it is 1.96499…, which PHP 8.4 rounded to 1.96.
        $order = $this->createOrder([['quantity' => 1, 'unit_price' => 2.885]], ['discount' => 0.92]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'discount' => 0.92, 'total' => 1.97]);

        $this->actingAs($this->admin)->patchJson("/api/orders/{$order->id}", ['discount' => 0.92])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total' => 1.97]);
        $this->assertEquals(1.97, $this->balance());
    }

    public function test_a_large_fixed_discount_that_leaves_half_a_piaster_rounds_up(): void
    {
        // 34108.615 − 33275.47 = 833.145; as floats 833.14499…, which PHP 8.3 (production) also rounded to 833.14.
        $order = $this->createOrder([['quantity' => 1, 'unit_price' => 34108.615]], ['discount' => 33275.47]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'discount' => 33275.47, 'total' => 833.15]);
        $this->assertEquals(833.15, $this->balance());
    }

    public function test_a_purchase_line_that_lands_on_half_a_piaster_rounds_up(): void
    {
        // 1.0125 × 6 = 6.075 exactly; as a float it is 6.07499…, which PHP 8.4 rounded to 6.07.
        $this->buy(6, 1.0125, 'base')->assertCreated();

        $this->assertDatabaseHas('purchase_order_items', ['product_id' => $this->nails->id, 'total' => 6.08]);
        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $this->supplier->id, 'total' => 6.08]);
        $this->assertEquals(6.08, $this->supplierBalance());
    }
}
