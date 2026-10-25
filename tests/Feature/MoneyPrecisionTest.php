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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Money keeps 4 decimals and is rounded only past the 4th: prices, costs, totals, payments and
// the ledger (W7, owner's decision 2026-10-15). A box of 1000 nails for 48.00 costs 0.048 a piece.
class MoneyPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Warehouse $shelf;
    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf    = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    // 1 box = 1000 pieces, optionally with pieces already on the shelf.
    private function nails(int $stock = 0, ?float $cost = null): Product
    {
        $product = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'secondary_unit'    => 'box',
            'conversion_factor' => 1000,
            'cost_price'        => $cost,
        ]);

        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $product->id,
            'quantity'     => $stock,
        ]);

        return $product;
    }

    private function buyOneBox(Product $product, float $boxPrice): void
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items'       => [[
                'product_id'   => $product->id,
                'quantity'     => 1,
                'unit_type'    => 'secondary',
                'warehouse_id' => $this->shelf->id,
                'unit_price'   => $boxPrice,
            ]],
        ])->assertStatus(201);
    }

    private function sell(Product $product, int $quantity, float $unitPrice): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [[
                'product_id' => $product->id,
                'quantity'   => $quantity,
                'unit_type'  => 'base',
                'unit_price' => $unitPrice,
            ]],
        ]);
    }

    public function test_a_piece_bought_by_the_box_costs_its_exact_share(): void
    {
        $nails = $this->nails();

        $this->buyOneBox($nails, 48.00);

        $this->assertEquals(0.048, (float) $nails->fresh()->cost_price);
    }

    public function test_the_average_cost_keeps_four_decimals(): void
    {
        // (500 × 0.05 + 1000 × 0.048) / 1500 = 0.048666… → 0.0487
        $nails = $this->nails(500, 0.05);

        $this->buyOneBox($nails, 48.00);

        $this->assertEquals(0.0487, (float) $nails->fresh()->cost_price);
    }

    public function test_a_four_decimal_sale_price_is_charged_exactly(): void
    {
        // 3 × 1.2345 = 3.7035, with nothing lost on the order, the ledger or the balance.
        $order = $this->sell($this->nails(100), 3, 1.2345)->assertStatus(201)->json();

        $this->assertEquals(1.2345, (float) DB::table('order_items')->where('order_id', $order['id'])->value('unit_price'));
        $this->assertEquals(3.7035, (float) Order::find($order['id'])->total);
        $this->assertEquals(3.7035, (float) DB::table('ledger_entries')
            ->where('reference_type', 'order')
            ->where('reference_id', $order['id'])
            ->where('type', 'ORDER_CHARGE')
            ->value('amount'));
        $this->assertEquals(3.7035, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_a_four_decimal_payment_settles_the_order_exactly(): void
    {
        $order = $this->sell($this->nails(100), 3, 1.2345)->assertStatus(201)->json();

        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id'    => $order['id'],
            'customer_id' => $this->customer->id,
            'amount'      => 3.7035,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertTrue(Order::find($order['id'])->isSettled());
        $this->assertEquals(0.0, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
        $this->assertDatabaseMissing('ledger_entries', ['type' => 'CREDIT_APPLY']);
    }

    public function test_a_fifth_decimal_is_rejected(): void
    {
        $this->sell($this->nails(100), 3, 1.23456)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.unit_price');

        $this->assertSame(0, Order::count());
    }
}
