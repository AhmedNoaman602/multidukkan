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
use App\Support\LocalDateRange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D32/D33: a created invoice is final and each line saves one historical cost per base unit
 * (order_items.unit_cost_at_sale), read under the product lock. Profit reads that saved cost,
 * so later purchases never change a past sale. Cancelling restores each line at its own sale
 * cost and recalculates the average.
 */
class OrderItemCostTest extends TestCase
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

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function product(?float $cost, int $stock, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'tenant_id'  => $this->tenant->id,
            'sku'        => 'SKU-'.uniqid(),
            'price'      => 20,
            'cost_price' => $cost,
        ], $overrides));

        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $product->id,
            'quantity'     => $stock,
        ]);

        return $product;
    }

    private function today(): string
    {
        return LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function sellLines(array $items, array $extra = []): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => $this->today(),
            'items'       => $items,
        ], $extra))->assertStatus(201)->json('id');

        return Order::findOrFail($id);
    }

    private function sell(Product $product, int $quantity, string $unitType = 'base'): Order
    {
        return $this->sellLines([['product_id' => $product->id, 'quantity' => $quantity, 'unit_type' => $unitType]]);
    }

    private function buy(Product $product, int $quantity, float $unitPrice): void
    {
        $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items'       => [[
                'product_id'   => $product->id,
                'quantity'     => $quantity,
                'unit_price'   => $unitPrice,
                'unit_type'    => 'base',
                'warehouse_id' => $this->shelf->id,
            ]],
        ])->assertStatus(201);
    }

    private function onlyItem(Order $order): object
    {
        $items = DB::table('order_items')->where('order_id', $order->id)->get();
        $this->assertCount(1, $items, 'Expected exactly one invoice line.');

        return $items->first();
    }

    /** Every ledger row of the customer, as stored. */
    private function ledgerRows(): array
    {
        return DB::table('ledger_entries')->where('customer_id', $this->customer->id)->orderBy('id')
            ->get(['id', 'type', 'amount', 'reference_type', 'reference_id', 'updated_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /** The money fields of the order, as stored. */
    private function orderRow(Order $order): array
    {
        return (array) DB::table('orders')->where('id', $order->id)
            ->first(['total', 'discount', 'manual_total', 'notes', 'order_date', 'updated_at']);
    }

    /** @return array<int, ?float> unit_cost_at_sale of each line, by line id */
    private function lineCosts(Order $order): array
    {
        return DB::table('order_items')->where('order_id', $order->id)->orderBy('id')
            ->pluck('unit_cost_at_sale')
            ->map(fn ($c) => $c === null ? null : (float) $c)
            ->all();
    }

    private function costOf(Product $product): float
    {
        return (float) DB::table('products')->where('id', $product->id)->value('cost_price');
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('inventory')->where('product_id', $product->id)->sum('quantity');
    }

    private function orderCost(Order $order): ?float
    {
        $cost = $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}")->assertOk()->json('cost');

        return $cost === null ? null : (float) $cost;
    }

    private function cancel(Order $order)
    {
        return $this->actingAs($this->admin)->deleteJson("/api/orders/{$order->id}");
    }

    // ─── Capture: one cost per line, at creation ────────────────────────────

    public function test_a_sale_saves_the_current_average_on_its_line(): void
    {
        $product = $this->product(10, 20);

        $order = $this->sell($product, 2);

        $this->assertSame([10.0], $this->lineCosts($order));
        $this->assertEquals(20, $this->orderCost($order));
    }

    public function test_a_box_line_saves_the_cost_per_piece(): void
    {
        $product = $this->product(4, 100, ['secondary_unit' => 'box', 'conversion_factor' => 12]);

        $order = $this->sell($product, 2, 'secondary');

        $this->assertSame([4.0], $this->lineCosts($order), 'The snapshot is per base unit, like products.cost_price.');
        $this->assertEquals(96, $this->orderCost($order), '2 boxes × 12 × 4');
    }

    public function test_a_purchase_after_a_sale_does_not_change_that_sale_s_cost(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);

        $this->buy($product, 18, 14);

        $this->assertEquals(12, $this->costOf($product), 'Setup: the purchase moved the average to 12.');
        $this->assertSame([10.0], $this->lineCosts($order));
        $this->assertEquals(20, $this->orderCost($order), 'The sale was made at 10 a piece; a later purchase must not reprice it.');

        $report = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$this->today()}&to={$this->today()}")
            ->assertOk();
        $this->assertEquals(20, $report->json('profit_by_order.data.0.cost'));
        $this->assertEquals(20, $report->json('summary.gross_profit'));
    }

    public function test_two_lines_of_one_product_share_the_cost_captured_at_creation(): void
    {
        $product = $this->product(10, 20);

        $order = $this->sellLines([
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25],
        ]);

        $this->assertSame([10.0, 10.0], $this->lineCosts($order));
        $this->assertEquals(30, $this->orderCost($order));
    }

    public function test_a_sale_of_an_unknown_cost_product_saves_an_unknown_cost(): void
    {
        $product = $this->product(null, 10);

        $order = $this->sell($product, 2);

        $this->assertSame([null], $this->lineCosts($order));
        $shown = $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}")->assertOk();
        $this->assertNull($shown->json('cost'));
        $this->assertSame(1, $shown->json('unknown_cost_lines'));
        $this->assertNull($shown->json('items.0.cost_price'));
    }

    public function test_an_order_with_one_unknown_line_stays_out_of_profit(): void
    {
        $unknown = $this->product(null, 20);
        $known   = $this->product(10, 20);
        $this->sellLines([
            ['product_id' => $unknown->id, 'quantity' => 2],
            ['product_id' => $known->id, 'quantity' => 1],
        ]);
        $this->sell($known, 1);

        $summary = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$this->today()}&to={$this->today()}")
            ->assertOk()
            ->json('summary');
        $this->assertEquals(80, $summary['total_revenue'], 'Both orders stay in revenue: 60 + 20.');
        $this->assertEquals(10, $summary['gross_profit'], 'Only the fully known order counts: 20 − 10.');
        $this->assertSame(1, $summary['orders_excluded_from_profit']);
        $this->assertEquals(60, $summary['sales_excluded_from_profit']);
    }

    // ─── D32: a created invoice is final ────────────────────────────────────

    public function test_adding_a_product_to_a_created_invoice_is_refused(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);

        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/items", ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.order_is_final'));

        $this->assertSame(2, (int) $this->onlyItem($order)->quantity);
        $this->assertSame(18, $this->stockOf($product));
        $this->assertEquals(40, (float) $order->fresh()->total);
    }

    public function test_changing_a_line_s_quantity_or_price_is_refused(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $item    = $this->onlyItem($order);

        foreach ([['quantity' => 3], ['quantity' => 1], ['unit_price' => 15]] as $change) {
            $this->actingAs($this->admin)
                ->patchJson("/api/orders/{$order->id}/items/{$item->id}", $change)
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.order_is_final'));
        }

        $line = $this->onlyItem($order);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertEquals(20, (float) $line->unit_price);
        $this->assertSame(18, $this->stockOf($product));
        $this->assertEquals(40, (float) $order->fresh()->total);
    }

    public function test_editing_the_discount_or_the_total_after_creation_is_refused(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id' => $order->id, 'customer_id' => $this->customer->id, 'amount' => 15, 'method' => 'cash',
        ])->assertStatus(201);

        $ledgerBefore = $this->ledgerRows();
        $orderBefore  = $this->orderRow($order);

        foreach ([
            ['discount' => 5, 'discount_type' => 'amount'],
            ['discount' => 10, 'discount_type' => 'percent'],
            ['manual_total' => 30],
            ['manual_total' => 30, 'notes' => 'with a note'],
        ] as $change) {
            $this->actingAs($this->admin)
                ->patchJson("/api/orders/{$order->id}", $change)
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.order_is_final'));
        }

        $this->assertEquals($orderBefore, $this->orderRow($order));
        $this->assertEquals($ledgerBefore, $this->ledgerRows());
        $this->assertEquals(25, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_notes_can_still_be_edited_after_creation(): void
    {
        $order = $this->sell($this->product(10, 20), 2);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}", ['notes' => 'deliver after 5pm'])
            ->assertOk();

        $this->assertSame('deliver after 5pm', $order->fresh()->notes);
    }

    public function test_the_order_date_can_still_be_edited_after_creation(): void
    {
        $order        = $this->sell($this->product(10, 20), 2);
        $ledgerBefore = $this->ledgerRows();
        $yesterday    = LocalDateRange::today(LocalDateRange::businessTimezone())->subDay()->toDateString();

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}", ['order_date' => $yesterday])
            ->assertOk()
            ->assertJsonPath('total', 40);

        $this->assertSame($yesterday, DB::table('orders')->where('id', $order->id)->value('order_date'));
        $this->assertEquals($ledgerBefore, $this->ledgerRows());
    }

    public function test_manual_total_is_still_allowed_when_creating_an_invoice(): void
    {
        $product = $this->product(10, 20);

        $order = $this->sellLines([['product_id' => $product->id, 'quantity' => 2]], ['manual_total' => 35]);

        $this->assertEquals(35, (float) $order->total);
        $this->assertEquals(35, (float) $order->manual_total);
    }

    // ─── Cancel (P2): each line back at its own sale cost ────────────────────

    public function test_cancelling_restores_each_line_at_its_sale_cost(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $this->buy($product, 18, 14);

        $this->cancel($order)->assertOk();

        $this->assertSame(38, $this->stockOf($product));
        $this->assertEquals(
            11.8947,
            $this->costOf($product),
            '36 × 12 + 2 × 10 = 452, / 38 — the same as 20 @ 10 + 18 @ 14 with no sale at all.'
        );
    }

    public function test_cancelling_an_order_with_products_in_reverse_id_order_restores_each_at_its_cost(): void
    {
        $p1 = $this->product(10, 20);
        $p2 = $this->product(4, 20);
        $order = $this->sellLines([
            ['product_id' => $p2->id, 'quantity' => 3],
            ['product_id' => $p1->id, 'quantity' => 2],
        ]);
        $this->buy($p1, 18, 14);
        $this->buy($p2, 17, 6);
        $this->assertEquals(12, $this->costOf($p1), 'Setup: (18 × 10 + 18 × 14) / 36');
        $this->assertEquals(5, $this->costOf($p2), 'Setup: (17 × 4 + 17 × 6) / 34');

        $this->cancel($order)->assertOk();

        $this->assertSame(38, $this->stockOf($p1));
        $this->assertEquals(11.8947, $this->costOf($p1), '(36 × 12 + 2 × 10) / 38 = 452 / 38');
        $this->assertSame(37, $this->stockOf($p2));
        $this->assertEquals(4.9189, $this->costOf($p2), '(34 × 5 + 3 × 4) / 37 = 182 / 37');
    }

    public function test_cancelling_two_lines_of_one_product_averages_them_once(): void
    {
        $product = $this->product(10, 20);
        $order = $this->sellLines([
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 25],
        ]);
        $this->buy($product, 17, 14);
        $this->assertEquals(12, $this->costOf($product), 'Setup: (17 × 10 + 17 × 14) / 34');

        $this->cancel($order)->assertOk();

        $this->assertSame(37, $this->stockOf($product));
        $this->assertEquals(11.8378, $this->costOf($product), '(34 × 12 + 3 × 10) / 37 = 438 / 37');
        $this->assertSame(2, DB::table('inventory_transactions')
            ->where('reference_type', Order::class)->where('reference_id', $order->id)
            ->where('type', 'RETURN')->count(), 'One movement per invoice line.');
    }

    public function test_cancelling_an_unknown_cost_line_keeps_today_s_average(): void
    {
        $product = $this->product(null, 10);
        $order   = $this->sell($product, 2);
        DB::table('products')->where('id', $product->id)->update(['cost_price' => 15]);

        $this->cancel($order)->assertOk();

        $this->assertSame(10, $this->stockOf($product));
        $this->assertEquals(15, $this->costOf($product), 'D27: unknown cost goes back at today\'s average, which stays 15.');
    }

    public function test_cancelling_twice_is_refused_and_changes_nothing(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $this->buy($product, 18, 14);
        $this->cancel($order)->assertOk();

        $this->cancel($order)->assertStatus(422);

        $this->assertSame(38, $this->stockOf($product));
        $this->assertEquals(11.8947, $this->costOf($product));
    }

    public function test_a_cancellation_that_fails_midway_changes_no_stock_or_cost(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $this->buy($product, 18, 14);
        $this->failOnQuery('insert into `ledger_entries`');

        $this->cancel($order)->assertStatus(500);

        $this->assertSame(36, $this->stockOf($product), 'Stock was restored before the failure and must be rolled back.');
        $this->assertEquals(12, $this->costOf($product));
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    /** Throws once, right after the first query that starts with $sqlPrefix, to fail a request midway. */
    private function failOnQuery(string $sqlPrefix): void
    {
        $armed = true;
        DB::listen(function ($query) use ($sqlPrefix, &$armed) {
            if ($armed && str_starts_with($query->sql, $sqlPrefix)) {
                $armed = false;
                throw new \RuntimeException("Forced failure after: {$sqlPrefix}");
            }
        });
    }

    // ─── Database guarantee ─────────────────────────────────────────────────

    public function test_a_line_cannot_store_a_negative_cost(): void
    {
        $order = $this->sell($this->product(10, 20), 2);
        $itemId = $this->onlyItem($order)->id;

        try {
            DB::table('order_items')->where('id', $itemId)->update(['unit_cost_at_sale' => -1]);
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1] ?? null, 'Expected a CHECK constraint violation, got: '.$e->getMessage());

            return;
        }

        $this->fail('The database accepted a negative unit_cost_at_sale.');
    }

    // ─── Performance ─────────────────────────────────────────────────────────

    public function test_the_report_does_not_run_more_queries_for_more_orders(): void
    {
        $product = $this->product(10, 100);
        $url = "/api/reports/daily?from={$this->today()}&to={$this->today()}";

        $this->sell($product, 1);
        $few = $this->countQueries(fn () => $this->actingAs($this->admin)->getJson($url)->assertOk());

        foreach (range(1, 4) as $_) {
            $this->sell($product, 1);
        }
        $many = $this->countQueries(fn () => $this->actingAs($this->admin)->getJson($url)->assertOk());

        $this->assertSame($few, $many, 'Report queries grew with the number of orders (N+1).');
    }

    private function countQueries(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
