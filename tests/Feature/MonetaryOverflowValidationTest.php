<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hardening: user-controlled monetary inputs must be capped to the target
 * DECIMAL column's precision/scale so an out-of-range value returns a clean
 * 422 instead of overflowing the column and producing a 500.
 *
 * DECIMAL(10,2) -> max 99,999,999.99
 * DECIMAL(12,2) -> max 9,999,999,999.99  (orders.total, via manual_total)
 */
class MonetaryOverflowValidationTest extends TestCase
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
        $this->product  = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price'     => 300,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
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

    private function createProduct(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/products', array_merge([
            'name'  => 'Widget',
            'sku'   => 'SKU-' . uniqid(),
            'price' => 100,
        ], $overrides));
    }

    private function createOrder(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
            ],
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────
    // DECIMAL(10,2) — product price (no business cap on the value)
    // ─────────────────────────────────────────────────────────

    public function test_price_at_column_maximum_is_accepted(): void
    {
        $this->createProduct(['price' => 99999999.99])
            ->assertStatus(201);

        $this->assertDatabaseHas('products', ['price' => 99999999.99]);
    }

    public function test_price_exceeding_column_maximum_returns_422(): void
    {
        $this->createProduct(['price' => 100000000]) // 9 integer digits — overflows decimal(10,2)
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $this->assertDatabaseMissing('products', ['price' => 100000000]);
    }

    public function test_price_with_too_many_decimals_returns_422(): void
    {
        $this->createProduct(['price' => 10.555])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    }

    public function test_price_tier_and_cost_price_are_also_capped(): void
    {
        $this->createProduct(['price' => 100, 'price_a' => 100000000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_a');

        $this->createProduct(['price' => 100, 'cost_price' => 10.999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cost_price');
    }

    // ─────────────────────────────────────────────────────────
    // DECIMAL(12,2) — orders.total via manual_total (larger cap)
    // ─────────────────────────────────────────────────────────

    public function test_manual_total_accepts_a_value_above_the_10_2_cap_but_within_12_2(): void
    {
        // 1,000,000,000.00 overflows decimal(10,2) but fits decimal(12,2).
        // Proves we did NOT blindly apply the 99,999,999.99 cap here.
        $response = $this->createOrder(['manual_total' => 1000000000.00])
            ->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'id'    => $response->json('id'),
            'total' => 1000000000.00,
        ]);
    }

    public function test_manual_total_exceeding_the_12_2_maximum_returns_422(): void
    {
        $this->createOrder(['manual_total' => 10000000000.00]) // 11 integer digits — overflows decimal(12,2)
            ->assertStatus(422)
            ->assertJsonValidationErrors('manual_total');

        $this->assertDatabaseMissing('orders', ['total' => 10000000000.00]);
    }

    public function test_manual_total_with_too_many_decimals_returns_422(): void
    {
        $this->createOrder(['manual_total' => 500.555])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manual_total');
    }

    // ─────────────────────────────────────────────────────────
    // Transaction paths must return 422, never a DB-overflow 500
    // ─────────────────────────────────────────────────────────

    public function test_payment_overflow_returns_422_not_500(): void
    {
        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => 500,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 100000000000, // wildly over decimal(10,2)
            'method'      => 'cash',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_customer_credit_overflow_returns_422(): void
    {
        $this->actingAs($this->user)->postJson("/api/customers/{$this->customer->id}/credit", [
            'amount'      => 100000000, // overflows decimal(10,2)
            'description' => 'Overpayment',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    // ─────────────────────────────────────────────────────────
    // products.opening_quantity — decimal(10,2), integer-validated
    // (whole-number max that fits 8 integer digits = 99,999,999)
    // ─────────────────────────────────────────────────────────

    public function test_opening_quantity_at_column_maximum_is_accepted(): void
    {
        $this->createProduct(['sku' => 'OQ-MAX', 'opening_quantity' => 99999999])
            ->assertStatus(201);

        $this->assertDatabaseHas('products', [
            'sku'              => 'OQ-MAX',
            'opening_quantity' => 99999999,
        ]);
    }

    public function test_opening_quantity_exceeding_column_maximum_returns_422(): void
    {
        $this->createProduct(['sku' => 'OQ-OVER', 'opening_quantity' => 100000000]) // 9 digits — overflows decimal(10,2)
            ->assertStatus(422)
            ->assertJsonValidationErrors('opening_quantity');

        $this->assertDatabaseMissing('products', ['sku' => 'OQ-OVER']);
    }
}
