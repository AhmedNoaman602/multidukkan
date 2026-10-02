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
 * manual_total and discount are mutually exclusive: an override replaces the discount,
 * and a later discount or item edit replaces the override.
 */
class ManualTotalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Product $product;
    private User $admin;
    private User $staff;
    private Warehouse $warehouse;

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
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'role'      => 'store_staff',
        ]);
        $this->warehouse = Warehouse::factory()->shelf()->create([
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

    // subtotal for the default order is 2 x 300 = 600

    private function createOrder(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $overrides));
    }

    private function pay(int $orderId, float $amount): void
    {
        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => $amount,
            'method'      => 'cash',
        ])->assertStatus(201);
    }

    private function assertCharge(int $orderId, float $amount): void
    {
        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => 'order',
            'reference_id'   => $orderId,
            'type'           => 'ORDER_CHARGE',
            'amount'         => $amount,
        ]);
    }

    // ─────────────────────────────────────────
    // Create
    // ─────────────────────────────────────────

    public function test_manual_total_clears_a_fixed_discount_on_creation(): void
    {
        $id = $this->createOrder(['manual_total' => 500, 'discount' => 50])
            ->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 0, 'manual_total' => 500, 'total' => 500]);
        $this->assertCharge($id, 500);
    }

    public function test_manual_total_clears_a_percentage_discount_on_creation(): void
    {
        $id = $this->createOrder(['manual_total' => 500, 'discount' => 10, 'discount_type' => 'percent'])
            ->assertStatus(201)->json('id');

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 0, 'manual_total' => 500, 'total' => 500]);
        $this->assertCharge($id, 500);
    }

    public function test_quick_sale_with_manual_total_is_paid_at_the_override_amount(): void
    {
        $id = $this->createOrder([
            'manual_total'    => 500,
            'discount'        => 50,
            'pay_immediately' => true,
            'payment_method'  => 'cash',
        ])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('payments', ['order_id' => $id, 'amount' => 500, 'method' => 'cash']);
        $this->assertTrue(Order::find($id)->isSettled());
    }

    public function test_order_without_manual_total_stores_null(): void
    {
        $response = $this->createOrder(['discount' => 100])->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'id'           => $response->json('id'),
            'discount'     => 100,
            'manual_total' => null,
            'total'        => 500,
        ]);
        $this->assertNull($response->json('manual_total'));
    }

    // ─────────────────────────────────────────
    // Edit
    // ─────────────────────────────────────────

    public function test_editing_manual_total_clears_the_discount_and_updates_the_ledger(): void
    {
        $id = $this->createOrder(['discount' => 100])->assertStatus(201)->json('id');

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['manual_total' => 450])
            ->assertStatus(200);

        $this->assertEquals(450, $response->json('manual_total'));
        $this->assertEquals(450, $response->json('total'));
        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 0, 'manual_total' => 450, 'total' => 450]);
        $this->assertCharge($id, 450);
    }

    public function test_manual_total_wins_when_sent_with_a_discount_on_edit(): void
    {
        $id = $this->createOrder()->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['manual_total' => 450, 'discount' => 100])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 0, 'manual_total' => 450, 'total' => 450]);
        $this->assertCharge($id, 450);
    }

    public function test_editing_the_discount_clears_the_manual_total(): void
    {
        $id = $this->createOrder(['manual_total' => 500])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['discount' => 100])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 100, 'manual_total' => null, 'total' => 500]);
        $this->assertCharge($id, 500);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['discount' => 0])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 0, 'manual_total' => null, 'total' => 600]);
        $this->assertCharge($id, 600);
    }

    public function test_editing_only_notes_keeps_the_manual_total(): void
    {
        $id = $this->createOrder(['manual_total' => 500])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['notes' => 'called the customer'])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id'           => $id,
            'notes'        => 'called the customer',
            'discount'     => 0,
            'manual_total' => 500,
            'total'        => 500,
        ]);
        $this->assertCharge($id, 500);
    }

    public function test_manual_total_cannot_be_edited_on_a_fully_paid_order(): void
    {
        $id = $this->createOrder()->assertStatus(201)->json('id');
        $this->pay($id, 600);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['manual_total' => 450])
            ->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $id, 'manual_total' => null, 'total' => 600]);
        $this->assertCharge($id, 600);
    }

    public function test_staff_cannot_edit_manual_total_on_a_partially_paid_order(): void
    {
        $id = $this->createOrder()->assertStatus(201)->json('id');
        $this->pay($id, 100);

        $this->actingAs($this->staff)
            ->patchJson("/api/orders/{$id}", ['manual_total' => 450])
            ->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $id, 'manual_total' => null, 'total' => 600]);
        $this->assertCharge($id, 600);
    }

    // ─────────────────────────────────────────
    // Items
    // ─────────────────────────────────────────

    public function test_adding_an_item_clears_the_manual_total_and_recalculates(): void
    {
        $id = $this->createOrder(['manual_total' => 500])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$id}/items", [
                'product_id'   => $this->product->id,
                'quantity'     => 1,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $id, 'discount' => 0, 'manual_total' => null, 'total' => 900]);
        $this->assertCharge($id, 900);
    }
}
