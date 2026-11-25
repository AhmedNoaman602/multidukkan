<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Purchase numbers are never reused: a cancelled purchase keeps its number, so the next
 * purchase takes the number after the highest one ever issued this year, cancelled or not.
 */
class PurchaseOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Warehouse $shelf;
    private Product $product;
    private Supplier $supplier;
    private User $admin;
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $store          = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf    = $store->shelf;
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'unit' => 'pcs']);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->year     = LocalDateRange::today(LocalDateRange::businessTimezone())->year;
    }

    private function purchase()
    {
        return $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items'       => [[
                'product_id'   => $this->product->id,
                'warehouse_id' => $this->shelf->id,
                'quantity'     => 5,
                'unit_price'   => 10,
            ]],
        ]);
    }

    private function number(int $poId): string
    {
        return DB::table('purchase_orders')->where('id', $poId)->value('invoice_number');
    }

    private function cancel(int $poId): void
    {
        $this->actingAs($this->admin)->deleteJson("/api/purchase-orders/{$poId}")->assertOk();
    }

    public function test_a_purchase_after_the_newest_one_was_cancelled_gets_the_next_number(): void
    {
        $first  = $this->purchase()->assertStatus(201)->json('data.id');
        $second = $this->purchase()->assertStatus(201)->json('data.id');
        $this->cancel($second);

        $response = $this->purchase();

        $this->assertSame(201, $response->status(),
            'A new purchase after cancelling the newest one answered '.$response->status().': '.substr($response->getContent(), 0, 200));
        $this->assertSame("{$this->year}-001", $this->number($first));
        $this->assertSame("{$this->year}-002", $this->number($second), 'The cancelled purchase keeps its number.');
        $this->assertSame("{$this->year}-003", $this->number($response->json('data.id')), 'Its number is never reused.');
    }

    public function test_cancelling_an_older_purchase_does_not_change_the_next_number(): void
    {
        $first = $this->purchase()->assertStatus(201)->json('data.id');
        $this->purchase()->assertStatus(201);
        $this->cancel($first);

        $third = $this->purchase()->assertStatus(201)->json('data.id');

        $this->assertSame("{$this->year}-003", $this->number($third));
    }

    public function test_the_only_purchase_of_the_year_cancelled_still_moves_the_number_on(): void
    {
        $first = $this->purchase()->assertStatus(201)->json('data.id');
        $this->cancel($first);

        $next = $this->purchase()->assertStatus(201)->json('data.id');

        $this->assertSame("{$this->year}-002", $this->number($next));
        $this->assertSame(2, PurchaseOrder::withTrashed()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_numbers_are_counted_per_shop(): void
    {
        $other      = Tenant::factory()->create();
        $otherStore = Store::factory()->withShelf()->create(['tenant_id' => $other->id]);
        $otherAdmin = User::factory()->create(['tenant_id' => $other->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $otherPo    = $this->actingAs($otherAdmin)->postJson('/api/purchase-orders', [
            'supplier_id' => Supplier::factory()->create(['tenant_id' => $other->id])->id,
            'items'       => [[
                'product_id'   => Product::factory()->create(['tenant_id' => $other->id, 'unit' => 'pcs'])->id,
                'warehouse_id' => $otherStore->shelf->id,
                'quantity'     => 1,
                'unit_price'   => 10,
            ]],
        ])->assertStatus(201)->json('data.id');
        $this->actingAs($otherAdmin)->deleteJson("/api/purchase-orders/{$otherPo}")->assertOk();

        $mine = $this->purchase()->assertStatus(201)->json('data.id');

        $this->assertSame("{$this->year}-001", $this->number($mine), "Another shop's cancelled purchase doesn't affect this shop's numbers.");
    }
}
