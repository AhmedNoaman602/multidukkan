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
use App\Support\LocalDateRange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 1 (D30, D31): every quantity added to an order line saves its own cost allocation
 * in order_item_costs; profit reads those saved costs, so later purchases never change a
 * past sale. Decreases take from the newest allocation, cancels restore each allocation at
 * its own cost and recalculate the average.
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

    private function sell(Product $product, int $quantity, string $unitType = 'base'): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => $this->today(),
            'items'       => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_type' => $unitType]],
        ])->assertStatus(201)->json('id');

        return Order::findOrFail($id);
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

    private function addItem(Order $order, Product $product, int $quantity, ?float $unitPrice = null)
    {
        return $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/items", array_filter([
            'product_id' => $product->id,
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
        ], fn ($v) => $v !== null));
    }

    private function setQuantity(Order $order, int $itemId, int $quantity)
    {
        return $this->actingAs($this->admin)->patchJson("/api/orders/{$order->id}/items/{$itemId}", [
            'quantity' => $quantity,
        ]);
    }

    private function onlyItemId(Order $order): int
    {
        $ids = DB::table('order_items')->where('order_id', $order->id)->pluck('id');
        $this->assertCount(1, $ids, 'Expected exactly one visible order line.');

        return $ids->first();
    }

    /** @return array<int, array{0: int, 1: ?float, 2: ?string, 3: int}> [base_quantity, unit_cost, source, returned] oldest first */
    private function allocations(int $itemId): array
    {
        return DB::table('order_item_costs')
            ->where('order_item_id', $itemId)
            ->orderBy('id')
            ->get()
            ->map(fn ($a) => [
                (int) $a->base_quantity,
                $a->unit_cost === null ? null : (float) $a->unit_cost,
                $a->unit_cost_source,
                (int) $a->returned_quantity,
            ])
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

    /** Every line's allocations add up to the line's quantity in base units. */
    private function assertAllocationsMatchLines(Order $order): void
    {
        foreach (DB::table('order_items')->where('order_id', $order->id)->get() as $item) {
            $allocated = (int) DB::table('order_item_costs')->where('order_item_id', $item->id)->sum('base_quantity');

            $this->assertSame(
                $item->quantity * $item->conversion_factor,
                $allocated,
                "Line {$item->id}: allocations don't add up to the line's base quantity."
            );
        }
    }

    /**
     * Shared scenario (decisions doc §16 D30): 20 on the shelf at 10. Sell 2 (A1: 2 @ 10).
     * Buy 18 @ 14 → 36 @ 12. Add 2 more to the same line (A2: 2 @ 12) → 34 @ 12, cost 44.
     *
     * @return array{0: Product, 1: Order, 2: int}
     */
    private function twoAdditionsAtDifferentCosts(): array
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $this->buy($product, 18, 14);
        $this->addItem($order, $product, 2)->assertSuccessful();

        return [$product, $order, $this->onlyItemId($order)];
    }

    // ─── Capture ─────────────────────────────────────────────────────────────

    public function test_a_sale_saves_one_allocation_per_line_at_the_current_average(): void
    {
        $product = $this->product(10, 20);

        $order = $this->sell($product, 2);

        $this->assertSame([[2, 10.0, 'average_at_sale', 0]], $this->allocations($this->onlyItemId($order)));
    }

    public function test_a_box_line_saves_base_units_and_the_cost_per_piece(): void
    {
        $product = $this->product(4, 100, ['secondary_unit' => 'box', 'conversion_factor' => 12]);

        $order = $this->sell($product, 2, 'secondary');

        $this->assertSame([[24, 4.0, 'average_at_sale', 0]], $this->allocations($this->onlyItemId($order)));
        $this->assertEquals(96, $this->orderCost($order));
    }

    public function test_a_purchase_after_a_sale_does_not_change_that_sale_s_cost(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);

        $this->buy($product, 18, 14);

        $this->assertEquals(12, $this->costOf($product), 'Setup: the purchase moved the average to 12.');
        $this->assertEquals(20, $this->orderCost($order), 'The sale was made at 10 a piece; a later purchase must not reprice it.');

        $report = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$this->today()}&to={$this->today()}")
            ->assertOk();
        $this->assertEquals(20, $report->json('profit_by_order.data.0.cost'));
        $this->assertEquals(20, $report->json('summary.gross_profit'));
    }

    public function test_adding_the_same_product_again_keeps_one_line_and_two_allocations(): void
    {
        [, $order, $itemId] = $this->twoAdditionsAtDifferentCosts();

        $this->assertSame(4, (int) DB::table('order_items')->where('id', $itemId)->value('quantity'));
        $this->assertSame([[2, 10.0, 'average_at_sale', 0], [2, 12.0, 'average_at_sale', 0]], $this->allocations($itemId));
        $this->assertEquals(44, $this->orderCost($order));
        $this->assertAllocationsMatchLines($order);
    }

    public function test_adding_a_line_at_another_price_saves_its_own_allocation(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $this->buy($product, 18, 14);

        $this->addItem($order, $product, 3, 25)->assertSuccessful();

        $newItemId = DB::table('order_items')->where('order_id', $order->id)->max('id');
        $this->assertSame([[3, 12.0, 'average_at_sale', 0]], $this->allocations($newItemId));
        $this->assertEquals(20 + 36, $this->orderCost($order));
        $this->assertAllocationsMatchLines($order);
    }

    public function test_increasing_a_quantity_adds_an_allocation_at_the_current_average(): void
    {
        $product = $this->product(10, 20);
        $order   = $this->sell($product, 2);
        $itemId  = $this->onlyItemId($order);
        $this->buy($product, 18, 14);

        $this->setQuantity($order, $itemId, 4)->assertSuccessful();

        $this->assertSame([[2, 10.0, 'average_at_sale', 0], [2, 12.0, 'average_at_sale', 0]], $this->allocations($itemId));
        $this->assertEquals(44, $this->orderCost($order));
        $this->assertAllocationsMatchLines($order);
    }

    public function test_a_sale_of_an_unknown_cost_product_saves_an_unknown_allocation(): void
    {
        $product = $this->product(null, 10);

        $order = $this->sell($product, 2);

        $this->assertSame([[2, null, null, 0]], $this->allocations($this->onlyItemId($order)));
        $this->assertNull($this->orderCost($order));
    }

    /**
     * A line sold while its cost was unknown, increased later at a known cost: the line holds
     * one unknown and one known allocation. 4b/D27: the whole line, and so the whole order, is
     * left out of profit — the known part is stored as-is (never 0) but not counted on its own.
     */
    public function test_a_line_with_an_unknown_and_a_known_allocation_stays_out_of_profit(): void
    {
        $mixed = $this->product(null, 20);
        $order = $this->sell($mixed, 2);
        $itemId = $this->onlyItemId($order);
        DB::table('products')->where('id', $mixed->id)->update(['cost_price' => 12]);
        $this->setQuantity($order, $itemId, 4)->assertSuccessful();

        $known = $this->product(10, 20);
        $this->sell($known, 1);

        $this->assertSame([[2, null, null, 0], [2, 12.0, 'average_at_sale', 0]], $this->allocations($itemId));

        $shown = $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}")->assertOk();
        $this->assertNull($shown->json('cost'));
        $this->assertNull($shown->json('gross_profit'));
        $this->assertSame(1, $shown->json('unknown_cost_lines'));
        $this->assertNull($shown->json('items.0.cost_price'));

        $summary = $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$this->today()}&to={$this->today()}")
            ->assertOk()
            ->json('summary');
        $this->assertEquals(100, $summary['total_revenue'], 'Both orders stay in revenue: 4 × 20 + 1 × 20.');
        $this->assertEquals(10, $summary['gross_profit'], 'Only the fully known order counts: 20 − 10.');
        $this->assertSame(1, $summary['orders_excluded_from_profit']);
        $this->assertEquals(80, $summary['sales_excluded_from_profit']);
    }

    // ─── Decrease (P1) and cancel (P2), restored through the shared helper (P3) ─

    public function test_decreasing_takes_from_the_newest_allocation_and_restores_its_cost(): void
    {
        [$product, $order, $itemId] = $this->twoAdditionsAtDifferentCosts();

        $this->setQuantity($order, $itemId, 3)->assertSuccessful();

        $this->assertSame([[2, 10.0, 'average_at_sale', 0], [1, 12.0, 'average_at_sale', 0]], $this->allocations($itemId));
        $this->assertSame(35, $this->stockOf($product));
        $this->assertEquals(12, $this->costOf($product), '(34 × 12 + 1 × 12) / 35 = 12');
        $this->assertEquals(32, $this->orderCost($order));

        $this->setQuantity($order, $itemId, 1)->assertSuccessful();

        $this->assertSame([[1, 10.0, 'average_at_sale', 0]], $this->allocations($itemId), 'A2 is used up, then A1 gives one.');
        $this->assertSame(37, $this->stockOf($product));
        $this->assertEquals(11.9459, $this->costOf($product), '(35 × 12 + 12 + 10) / 37 = 442 / 37');
        $this->assertEquals(10, $this->orderCost($order));
        $this->assertAllocationsMatchLines($order);
    }

    /*
     * Decrease rule (D31/P1, Ahmed 2026-11-20): newest allocation first; never below an
     * allocation's returned quantity; a returned newest allocation is NOT skipped in favour
     * of older ones; and D2 freezes an order after any return, so every decrease is refused.
     * Returns are simulated by setting returned_quantity directly (the endpoint is slice 3a).
     */

    public function test_a_decrease_never_takes_more_than_the_quantity_still_unreturned(): void
    {
        [$product, $order, $itemId] = $this->twoAdditionsAtDifferentCosts();
        $newest = DB::table('order_item_costs')->where('order_item_id', $itemId)->max('id');
        DB::table('order_item_costs')->where('id', $newest)->update(['returned_quantity' => 2]);

        // 4 sold, 2 returned: only 2 are still with the customer, so 4 → 1 (−3) can't be done.
        $this->setQuantity($order, $itemId, 1)->assertStatus(422);

        $this->assertSame(4, (int) DB::table('order_items')->where('id', $itemId)->value('quantity'));
        $this->assertSame([[2, 10.0, 'average_at_sale', 0], [2, 12.0, 'average_at_sale', 2]], $this->allocations($itemId));
        $this->assertSame(34, $this->stockOf($product));
        $this->assertEquals(12, $this->costOf($product));
    }

    public function test_a_returned_newest_allocation_is_not_skipped_for_an_older_one(): void
    {
        [$product, $order, $itemId] = $this->twoAdditionsAtDifferentCosts();
        $newest = DB::table('order_item_costs')->where('order_item_id', $itemId)->max('id');
        DB::table('order_item_costs')->where('id', $newest)->update(['returned_quantity' => 2]);

        // A1 still has 2 unreturned, but taking from it to cover A2 is not allowed.
        $this->setQuantity($order, $itemId, 3)->assertStatus(422);

        $this->assertSame(4, (int) DB::table('order_items')->where('id', $itemId)->value('quantity'));
        $this->assertSame([[2, 10.0, 'average_at_sale', 0], [2, 12.0, 'average_at_sale', 2]], $this->allocations($itemId));
        $this->assertSame(34, $this->stockOf($product));
        $this->assertEquals(12, $this->costOf($product));
    }

    public function test_any_return_on_the_order_freezes_decreases(): void
    {
        [$product, $order, $itemId] = $this->twoAdditionsAtDifferentCosts();
        $oldest = DB::table('order_item_costs')->where('order_item_id', $itemId)->min('id');
        DB::table('order_item_costs')->where('id', $oldest)->update(['returned_quantity' => 1]);

        // The newest allocation (A2) is untouched, but D2 freezes the whole order after a return.
        $this->setQuantity($order, $itemId, 3)->assertStatus(422);

        $this->assertSame(4, (int) DB::table('order_items')->where('id', $itemId)->value('quantity'));
        $this->assertSame([[2, 10.0, 'average_at_sale', 1], [2, 12.0, 'average_at_sale', 0]], $this->allocations($itemId));
        $this->assertSame(34, $this->stockOf($product));
        $this->assertEquals(12, $this->costOf($product));
    }

    public function test_cancelling_restores_each_allocation_at_its_own_cost(): void
    {
        [$product, $order] = $this->twoAdditionsAtDifferentCosts();

        $this->actingAs($this->admin)->deleteJson("/api/orders/{$order->id}")->assertOk();

        $this->assertSame(38, $this->stockOf($product));
        $this->assertEquals(
            11.8947,
            $this->costOf($product),
            '34 × 12 + 2 × 10 + 2 × 12 = 452, / 38 — the same as 20 @ 10 + 18 @ 14 with no sale at all.'
        );
    }

    public function test_cancelling_an_unknown_cost_allocation_keeps_today_s_average(): void
    {
        $product = $this->product(null, 10);
        $order   = $this->sell($product, 2);
        DB::table('products')->where('id', $product->id)->update(['cost_price' => 15]);

        $this->actingAs($this->admin)->deleteJson("/api/orders/{$order->id}")->assertOk();

        $this->assertSame(10, $this->stockOf($product));
        $this->assertEquals(15, $this->costOf($product), 'D27: unknown cost goes back at today\'s average, which stays 15.');
    }

    // ─── Database guarantees returns will rely on ──────────────────────────────

    public function test_an_allocation_cannot_record_more_returned_than_it_sold(): void
    {
        [, , $itemId] = $this->twoAdditionsAtDifferentCosts();
        $oldest = DB::table('order_item_costs')->where('order_item_id', $itemId)->min('id');

        $this->assertCheckViolation(
            fn () => DB::table('order_item_costs')->where('id', $oldest)->update(['returned_quantity' => 3])
        );
    }

    public function test_an_allocation_must_hold_a_positive_quantity(): void
    {
        [, , $itemId] = $this->twoAdditionsAtDifferentCosts();

        $this->assertCheckViolation(fn () => DB::table('order_item_costs')->insert([
            'tenant_id'         => $this->tenant->id,
            'order_item_id'     => $itemId,
            'base_quantity'     => 0,
            'unit_cost'         => 10,
            'unit_cost_source'  => 'average_at_sale',
            'returned_quantity' => 0,
        ]));
    }

    /** Only a CHECK constraint failure counts — not a missing table or any other SQL error. */
    private function assertCheckViolation(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1] ?? null, 'Expected a CHECK constraint violation, got: '.$e->getMessage());

            return;
        }

        $this->fail('The database accepted a row that breaks the allocation rules.');
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
