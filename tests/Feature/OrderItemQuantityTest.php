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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Order quantities are whole numbers. A fractional quantity must be rejected before anything is
// saved: accepting it once stored a rounded quantity and charge but moved a truncated amount of stock.
class OrderItemQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Product $product;
    private User $admin;
    private Warehouse $shelf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    // An order of 1 × 100: stock 99, charge 100.
    private function orderOfOne(): Order
    {
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        return Order::latest('id')->firstOrFail();
    }

    // Everything a quantity change can touch: the line, the order total, the ledger charge and stock.
    private function state(Order $order): array
    {
        return [
            'items'        => DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get(['quantity', 'unit_price'])->toArray(),
            'total'        => (float) $order->fresh()->total,
            'charge'       => (float) DB::table('ledger_entries')
                ->where('type', 'ORDER_CHARGE')->where('reference_type', 'order')->where('reference_id', $order->id)
                ->value('amount'),
            'stock'        => (int) Inventory::where('product_id', $this->product->id)->sum('quantity'),
            'transactions' => DB::table('inventory_transactions')->where('product_id', $this->product->id)->count(),
        ];
    }

    public function test_adding_an_item_with_a_fractional_quantity_is_rejected_and_saves_nothing(): void
    {
        $order  = $this->orderOfOne();
        $before = $this->state($order);

        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/items", ['product_id' => $this->product->id, 'quantity' => 1.5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertEquals($before, $this->state($order));
    }

    public function test_editing_a_line_to_a_fractional_quantity_is_rejected_and_saves_nothing(): void
    {
        $order  = $this->orderOfOne();
        $itemId = DB::table('order_items')->where('order_id', $order->id)->value('id');
        $before = $this->state($order);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}/items/{$itemId}", ['quantity' => 3.5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertEquals($before, $this->state($order));
    }

    public function test_a_fractional_quantity_sent_as_text_is_rejected_too(): void
    {
        $order  = $this->orderOfOne();
        $before = $this->state($order);

        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/items", ['product_id' => $this->product->id, 'quantity' => '2.5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertEquals($before, $this->state($order));
    }

    public function test_whole_quantities_still_work_for_adding_and_editing(): void
    {
        $order = $this->orderOfOne();

        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/items", ['product_id' => $this->product->id, 'quantity' => 2])
            ->assertOk();

        $after = $this->state($order);
        $this->assertEquals(3, $after['items'][0]->quantity);
        $this->assertEquals(300.0, $after['total']);
        $this->assertEquals(300.0, $after['charge']);
        $this->assertEquals(97, $after['stock']);

        $itemId = DB::table('order_items')->where('order_id', $order->id)->value('id');
        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}/items/{$itemId}", ['quantity' => '5'])
            ->assertOk();

        $after = $this->state($order);
        $this->assertEquals(5, $after['items'][0]->quantity);
        $this->assertEquals(500.0, $after['total']);
        $this->assertEquals(500.0, $after['charge']);
        $this->assertEquals(95, $after['stock']);
    }

    public function test_creating_an_order_with_a_fractional_quantity_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 1.5]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        $this->assertSame(0, Order::count());
        $this->assertEquals(100, Inventory::where('product_id', $this->product->id)->sum('quantity'));
    }
}
