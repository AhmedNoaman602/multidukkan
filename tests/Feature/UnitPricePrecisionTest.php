<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AI\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

// Per-unit prices and costs keep 6 decimals; money that changes hands has 2. Each line is rounded
// to the piaster and the rounded lines are added up, so an invoice's lines always add up to it.
class UnitPricePrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Warehouse $shelf;
    private Customer $customer;
    private Supplier $supplier;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf    = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    // 1 box = 1000 pieces; `stock` pieces on the shelf.
    private function nails(array $attributes = [], int $stock = 0): Product
    {
        $product = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'secondary_unit'    => 'box',
            'conversion_factor' => 1000,
            ...$attributes,
        ]);

        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $product->id,
            'quantity'     => $stock,
        ]);

        return $product;
    }

    private function purchase(Product $product, int $quantity, string $unitType, string $unitPrice): array
    {
        return $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items'       => [[
                'product_id'   => $product->id,
                'quantity'     => $quantity,
                'unit_type'    => $unitType,
                'warehouse_id' => $this->shelf->id,
                'unit_price'   => $unitPrice,
            ]],
        ])->assertStatus(201)->json('data');
    }

    private function sell(Product $product, int $quantity, string $unitType = 'base', array $line = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_type' => $unitType, ...$line]],
        ]);
    }

    private function orderCharge(int $orderId): float
    {
        return (float) DB::table('ledger_entries')
            ->where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('type', 'ORDER_CHARGE')
            ->value('amount');
    }

    // ─── Stored and returned without rounding ────────────────────────────────

    public function test_price_and_cost_keep_six_decimals_end_to_end(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/products', [
            'name'       => 'Nail',
            'sku'        => 'NAIL-1',
            'price'      => '0.0125',
            'cost_price' => '0.01251',
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('products', ['id' => $id, 'price' => 0.0125, 'cost_price' => 0.01251]);

        $shown = $this->actingAs($this->admin)->getJson("/api/products/{$id}")->assertOk()->json('data');

        $this->assertSame('0.012500', $shown['price']);
        $this->assertEquals(0.01251, (float) $shown['cost_price']);
    }

    public function test_a_seventh_decimal_on_an_order_line_price_is_rejected_and_nothing_is_saved(): void
    {
        $product = $this->nails(['price' => 1], stock: 10);

        $this->sell($product, 1, line: ['unit_price' => '1.1234567'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.unit_price');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('inventory', ['product_id' => $product->id, 'quantity' => 10]);
    }

    public function test_a_payment_still_rejects_a_third_decimal_and_nothing_is_saved(): void
    {
        $product = $this->nails(['price' => 10], stock: 10);
        $orderId = $this->sell($product, 2)->assertStatus(201)->json('id');

        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => '12.567',
            'method'      => 'cash',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    // ─── Purchase cost per piece ─────────────────────────────────────────────

    public function test_a_carton_of_1000_for_12_50_costs_each_piece_0_0125(): void
    {
        $product = $this->nails();

        $po = $this->purchase($product, 1, 'secondary', '12.50');

        $this->assertEquals(0.0125, (float) $product->fresh()->cost_price);   // was 0.01 at 2 decimals
        $this->assertDatabaseHas('supplier_products', [
            'supplier_id' => $this->supplier->id, 'product_id' => $product->id, 'last_purchase_price' => 0.0125,
        ]);
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $po['id'], 'unit_price' => 12.5, 'total' => 12.5]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po['id'], 'total' => 12.5]);
    }

    public function test_a_carton_for_12_51_needs_five_decimals_and_keeps_them(): void
    {
        $product = $this->nails();

        $this->purchase($product, 1, 'secondary', '12.51');

        $this->assertEquals(0.01251, (float) $product->fresh()->cost_price);  // 4 decimals would give 0.0125
    }

    public function test_weighted_average_cost_keeps_fractions_of_a_piaster(): void
    {
        // 1000 pcs at 0.0125 already in stock + 1 box of 1000 at 15.00 → (12.50 + 15.00) / 2000 = 0.01375
        $product = $this->nails(['cost_price' => '0.0125'], stock: 1000);

        $this->purchase($product, 1, 'secondary', '15.00');

        $this->assertEquals(0.01375, (float) $product->fresh()->cost_price);
    }

    public function test_purchase_price_entered_per_piece_is_kept_and_the_total_is_rounded_to_piasters(): void
    {
        // 7 pieces at 0.0125 = 0.0875 → the supplier is owed 0.09
        $product = $this->nails();

        $po = $this->purchase($product, 7, 'base', '0.0125');

        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $po['id'], 'unit_price' => 0.0125, 'total' => 0.09]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po['id'], 'total' => 0.09]);
        $this->assertEquals(0.0125, (float) $product->fresh()->cost_price);
    }

    // ─── Selling: box price and rounding at the total ────────────────────────

    public function test_box_price_is_piece_price_times_box_size(): void
    {
        // 0.045 a piece → 45.00 a box. At 2 decimals the price became 0.05 → 50.00 a box.
        $product = $this->nails(['price' => '0.045'], stock: 2000);

        $orderId = $this->sell($product, 2, 'secondary')->assertStatus(201)->json('id');

        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'unit_price' => 45]);
        $this->assertEquals(90.0, (float) Order::find($orderId)->total);
        $this->assertEquals(90.0, $this->orderCharge($orderId));
    }

    public function test_a_sub_piaster_line_is_rounded_to_the_piaster_and_can_be_paid_exactly(): void
    {
        // 3 × 0.0125 = 0.0375 → charged 0.04
        $product = $this->nails(['price' => '0.0125'], stock: 10);

        $order = $this->sell($product, 3)->assertStatus(201)->json();

        $this->assertDatabaseHas('order_items', ['order_id' => $order['id'], 'unit_price' => 0.0125]);
        $this->assertEquals(0.04, (float) $order['total']);
        $this->assertEquals(0.04, (float) $order['items'][0]['total']);
        $this->assertEquals(0.04, $this->orderCharge($order['id']));

        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id'    => $order['id'],
            'customer_id' => $this->customer->id,
            'amount'      => '0.04',
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->actingAs($this->admin)->getJson("/api/orders/{$order['id']}")
            ->assertOk()
            ->assertJsonPath('status', 'paid');
    }

    // ─── Reports ─────────────────────────────────────────────────────────────

    public function test_report_cost_uses_the_cost_to_a_fraction_of_a_piaster(): void
    {
        // 1000 pcs sold at 0.02 = 20.00; cost 1000 × 0.0125 = 12.50 (10.00 if the cost were 0.01)
        $product = $this->nails(['price' => '0.02', 'cost_price' => '0.0125'], stock: 1000);
        $this->sell($product, 1000)->assertStatus(201);

        $today = now()->toDateString();
        $row = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$today}&to={$today}")
            ->assertOk()
            ->json('profit_by_order.data.0');

        $this->assertEquals(20.0, $row['revenue']);
        $this->assertEquals(12.5, $row['cost']);
        $this->assertEquals(7.5, $row['profit']);
    }

    // ─── Line-level rounding: the lines add up to what is charged ────────────

    // Three different products (identical lines would merge into one): 3 × 0.0125 = 0.0375 each.
    private function threeSubPiasterProducts(): array
    {
        return collect(['Screw A', 'Screw B', 'Screw C'])
            ->map(fn ($name) => $this->nails(['name' => $name, 'price' => '0.0125'], stock: 100))
            ->all();
    }

    private function orderOf(array $products, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 3], $products),
            ...$extra,
        ]);
    }

    public function test_three_lines_of_0_0375_show_0_04_each_and_add_up_to_the_charge(): void
    {
        $order = $this->orderOf($this->threeSubPiasterProducts())->assertStatus(201)->json();

        $lines = array_column($order['items'], 'total');
        $this->assertEquals([0.04, 0.04, 0.04], $lines);
        $this->assertEquals(round(array_sum($lines), 2), $order['subtotal']);
        $this->assertEquals(0.12, $order['subtotal']);
        $this->assertEquals(0.12, $order['total']);   // rounding once at the total would charge 0.11
        $this->assertEquals(0.12, (float) Order::find($order['id'])->total);
        $this->assertEquals(0.12, $this->orderCharge($order['id']));
    }

    public function test_a_percent_discount_applies_to_the_sum_of_the_rounded_lines(): void
    {
        // 50% of 0.12 = 0.06
        $order = $this->orderOf($this->threeSubPiasterProducts(), ['discount' => 50, 'discount_type' => 'percent'])
            ->assertStatus(201)->json();

        $this->assertEquals(0.12, $order['subtotal']);
        $this->assertEquals(0.06, $order['discount']);
        $this->assertEquals(0.06, $order['total']);
        $this->assertEquals(0.06, $this->orderCharge($order['id']));
    }

    public function test_a_manual_total_still_replaces_the_line_sum(): void
    {
        $order = $this->orderOf($this->threeSubPiasterProducts(), ['manual_total' => '0.10'])
            ->assertStatus(201)->json();

        $this->assertEquals(0.12, $order['subtotal']);
        $this->assertEquals(0.10, $order['total']);
        $this->assertEquals(0.10, $this->orderCharge($order['id']));
    }

    public function test_adding_a_line_and_changing_the_discount_recalculate_from_rounded_lines(): void
    {
        [$a, $b, $c] = $this->threeSubPiasterProducts();
        $orderId = $this->orderOf([$a, $b])->assertStatus(201)->json('id');
        $this->assertEquals(0.08, $this->orderCharge($orderId));

        $this->actingAs($this->admin)->postJson("/api/orders/{$orderId}/items", ['product_id' => $c->id, 'quantity' => 3])
            ->assertOk();
        $this->assertEquals(0.12, (float) Order::find($orderId)->total);   // 0.11 if rounded once
        $this->assertEquals(0.12, $this->orderCharge($orderId));

        $this->actingAs($this->admin)->patchJson("/api/orders/{$orderId}", ['discount' => '0.02', 'discount_type' => 'amount'])
            ->assertOk();
        $this->assertEquals(0.10, (float) Order::find($orderId)->total);
        $this->assertEquals(0.10, $this->orderCharge($orderId));
    }

    public function test_purchase_order_lines_add_up_to_the_total_and_the_supplier_debt(): void
    {
        $po = $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items'       => array_map(fn ($p) => [
                'product_id' => $p->id, 'quantity' => 3, 'unit_type' => 'base',
                'warehouse_id' => $this->shelf->id, 'unit_price' => '0.0125',
            ], $this->threeSubPiasterProducts()),
        ])->assertStatus(201)->json('data');

        $lines = array_column($po['items'], 'total');
        $this->assertEquals([0.04, 0.04, 0.04], $lines);
        $this->assertEquals(0.12, $po['subtotal']);
        $this->assertEquals(0.12, $po['total']);
        $this->assertEquals(0.12, (float) DB::table('ledger_entries')
            ->where('type', 'PURCHASE_CHARGE')
            ->where('reference_type', PurchaseOrder::class)
            ->where('reference_id', $po['id'])
            ->value('amount'));
    }

    public function test_report_revenue_per_product_adds_up_to_the_order_revenue(): void
    {
        $this->orderOf($this->threeSubPiasterProducts())->assertStatus(201);

        $today = now()->toDateString();
        $report = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$today}&to={$today}")
            ->assertOk()
            ->json();

        $perProduct = array_column($report['products_sold'], 'revenue');
        $this->assertEquals([0.04, 0.04, 0.04], $perProduct);
        $this->assertEquals(0.12, $report['summary']['total_revenue']);
        $this->assertEquals($report['summary']['total_revenue'], round(array_sum($perProduct), 2));
    }

    // ─── AI ──────────────────────────────────────────────────────────────────

    public function test_ai_sales_summary_sends_revenue_built_from_rounded_lines(): void
    {
        $sent = null;
        $this->mock(AIService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('generateInsights')->andReturnUsing(function (array $salesData) use (&$sent) {
                $sent = $salesData;

                return ['opportunity' => ['title' => 't', 'body' => 'b'], 'urgent' => ['title' => 't', 'body' => 'b'], 'trend' => ['title' => 't', 'body' => 'b']];
            });
        });

        // Three orders, each one line of 3 × 0.0125 = 0.0375 → 0.04
        $product = $this->nails(['name' => 'Screw', 'price' => '0.0125'], stock: 100);
        foreach (range(1, 3) as $ignored) {
            $this->sell($product, 3)->assertStatus(201);
        }

        $this->actingAs($this->admin)->getJson('/api/ai/insights')->assertOk();

        $this->assertSame(0.12, $sent['products'][0]['total_revenue']);   // not 0.1125
        $this->assertEquals(0.12, $sent['total_revenue']);
    }

    public function test_ai_chat_shows_unit_prices_without_trailing_zeros_but_with_their_precision(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);
        $this->nails(['name' => 'Nail', 'price' => '0.0125'], stock: 5);
        $this->nails(['name' => 'Hammer', 'price' => '150'], stock: 5);

        $this->actingAs($this->admin)->postJson('/api/ai/chat', ['message' => 'كم سعر المسمار؟'])->assertOk();

        $fake->assertRequest(function (array $requests) {
            $prompt = collect($requests[0]->systemPrompts())->map(fn ($p) => $p->content)->join("\n");

            $this->assertStringContainsString('السعر: 0.0125 جنيه', $prompt);
            $this->assertStringContainsString('السعر: 150 جنيه', $prompt);
            $this->assertStringNotContainsString('0.012500', $prompt);
            $this->assertStringNotContainsString('150.000000', $prompt);
        });
    }

    // ─── Ordinary two-decimal values are unaffected ──────────────────────────

    public function test_two_decimal_prices_behave_exactly_as_before(): void
    {
        $product = $this->nails(['price' => '150.25'], stock: 10);

        $order = $this->sell($product, 2)->assertStatus(201)->json();

        $this->assertDatabaseHas('order_items', ['order_id' => $order['id'], 'unit_price' => 150.25]);
        $this->assertEquals(300.5, (float) $order['total']);
        $this->assertEquals(300.5, $this->orderCharge($order['id']));
    }
}
