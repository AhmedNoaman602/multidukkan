<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderProfitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private Customer $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        // 1 box = 12 pcs, cost 4 per piece
        $this->product = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'price'             => 5,
            'cost_price'        => 4,
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);
    }

    // Each line is [quantity, conversionFactor, unitPrice]; unitPrice is per the line's unit.
    private function makeOrder(float $total, array ...$lines): Order
    {
        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => $total,
            'order_date'  => now()->toDateString(),
        ]);

        foreach ($lines as [$quantity, $factor, $unitPrice]) {
            OrderItem::factory()->create([
                'order_id'          => $order->id,
                'product_id'        => $this->product->id,
                'quantity'          => $quantity,
                'unit_price'        => $unitPrice,
                'unit_type'         => $factor > 1 ? 'secondary' : 'base',
                'conversion_factor' => $factor,
            ]);
        }

        return $order;
    }

    private function showAs(User $user, Order $order): array
    {
        return $this->actingAs($user)->getJson("/api/orders/{$order->id}")->assertOk()->json();
    }

    public function test_secondary_unit_sale_costs_its_base_quantity(): void
    {
        // 2 boxes at 60/box = 120; 24 pcs × 4 = 96 cost, not 2 × 4 = 8
        $order = $this->showAs($this->admin, $this->makeOrder(120, [2, 12, 60]));

        $this->assertEquals(96, $order['cost']);
        $this->assertEquals(24, $order['gross_profit']);
    }

    public function test_base_unit_sale_costs_cost_price_times_pieces(): void
    {
        // 24 pcs at 5 = 120; 24 × 4 = 96 cost
        $order = $this->showAs($this->admin, $this->makeOrder(120, [24, 1, 5]));

        $this->assertEquals(96, $order['cost']);
        $this->assertEquals(24, $order['gross_profit']);
    }

    public function test_order_cost_and_profit_match_the_report_for_the_same_order(): void
    {
        // 1 box at 60 + 5 pcs at 5 = 85, discounted to 80; cost (12 + 5) × 4 = 68
        $order = $this->makeOrder(80, [1, 12, 60], [5, 1, 5]);
        $order->update(['discount' => 5]);

        $shown = $this->showAs($this->admin, $order);

        $today  = now()->toDateString();
        $report = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$today}&to={$today}")
            ->assertOk()
            ->json('profit_by_order.data.0');

        $this->assertEquals(68, $shown['cost']);
        $this->assertEquals(12, $shown['gross_profit']);
        $this->assertEquals($report['cost'], $shown['cost']);
        $this->assertEquals($report['profit'], $shown['gross_profit']);
    }

    public function test_store_manager_and_staff_do_not_receive_order_cost_or_profit(): void
    {
        $order = $this->makeOrder(120, [2, 12, 60]);

        foreach (['store_manager', 'store_staff'] as $role) {
            $user = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'store_id'  => $this->store->id,
                'role'      => $role,
            ]);

            $shown = $this->showAs($user, $order);

            $this->assertArrayNotHasKey('cost', $shown, $role);
            $this->assertArrayNotHasKey('gross_profit', $shown, $role);
            $this->assertEquals(120, $shown['total'], $role);
        }
    }
}
