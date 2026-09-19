<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client sends the discount type and the entered value; the server decides the
 * monetary amount. A percentage is an input method, never a stored property.
 */
class DiscountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Product $product;
    private User $admin;
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

        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price'     => 250,
        ]);

        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);

        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 1000,
        ]);
    }

    /** @param array<string,mixed> $discount */
    private function createOrder(array $discount = [], int $quantity = 4)
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [[
                'product_id'   => $this->product->id,
                'quantity'     => $quantity,
                'warehouse_id' => $this->warehouse->id,
            ]],
        ], $discount));
    }

    // subtotal for the default order is 4 x 250 = 1000

    public function test_fixed_discount_is_subtracted_as_given(): void
    {
        $id = $this->createOrder(['discount' => 150])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 150.00, 'total' => 850.00]);
    }

    public function test_fixed_discount_is_the_default_when_no_type_is_sent(): void
    {
        $id = $this->createOrder(['discount' => 150, 'discount_type' => 'amount'])
            ->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 150.00, 'total' => 850.00]);
    }

    public function test_percentage_discount_is_calculated_by_the_server(): void
    {
        $id = $this->createOrder(['discount' => 10, 'discount_type' => 'percent'])
            ->assertStatus(201)->json('id');

        // 10% of 1000 = 100 stored as the monetary amount, never the percentage
        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 100.00, 'total' => 900.00]);
    }

    public function test_percentage_discount_rounds_to_two_decimals(): void
    {
        // 3 x 250 = 750; 12.5% = 93.75
        $id = $this->createOrder(['discount' => 12.5, 'discount_type' => 'percent'], 3)
            ->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 93.75, 'total' => 656.25]);
    }

    public function test_hundred_percent_discount_zeroes_the_total_without_going_negative(): void
    {
        $id = $this->createOrder(['discount' => 100, 'discount_type' => 'percent'])
            ->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 1000.00, 'total' => 0.00]);
    }

    public function test_fixed_discount_larger_than_subtotal_is_clamped_not_negative(): void
    {
        $id = $this->createOrder(['discount' => 5000])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 1000.00, 'total' => 0.00]);
    }

    public function test_percentage_above_one_hundred_is_rejected(): void
    {
        $this->createOrder(['discount' => 150, 'discount_type' => 'percent'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unknown_discount_type_is_rejected(): void
    {
        $this->createOrder(['discount' => 10, 'discount_type' => 'fraction'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_type');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_negative_discount_is_rejected(): void
    {
        $this->createOrder(['discount' => -50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_discount_type_is_not_persisted_as_a_column(): void
    {
        $id = $this->createOrder(['discount' => 10, 'discount_type' => 'percent'])
            ->assertStatus(201)->json('id');

        $row = (array) \DB::table('orders')->where('id', $id)->first();

        $this->assertArrayNotHasKey('discount_type', $row);
        $this->assertSame(100.0, (float) $row['discount']);
    }

    // ─────────────────────────────────────────
    // Update flow
    // ─────────────────────────────────────────

    public function test_update_applies_a_percentage_against_the_current_subtotal(): void
    {
        $id = $this->createOrder(['discount' => 0])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['discount' => 20, 'discount_type' => 'percent'])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 200.00, 'total' => 800.00]);
    }

    public function test_update_with_a_fixed_discount_stores_the_amount_unchanged(): void
    {
        $id = $this->createOrder(['discount' => 0])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['discount' => 325])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 325.00, 'total' => 675.00]);
    }

    public function test_update_rejects_a_percentage_above_one_hundred(): void
    {
        $id = $this->createOrder(['discount' => 0])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['discount' => 500, 'discount_type' => 'percent'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 0.00]);
    }

    public function test_update_clamps_a_fixed_discount_to_the_subtotal(): void
    {
        $id = $this->createOrder(['discount' => 0])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['discount' => 9999])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 1000.00, 'total' => 0.00]);
    }
}
