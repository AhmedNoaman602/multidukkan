<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalePriceOverrideRoleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected User $manager;
    protected User $staff;
    protected Customer $customer;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $shelf          = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->manager  = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_manager']);
        $this->staff    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_staff']);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'price_tier' => 'a']);
        $this->product  = Product::factory()->create([
            'tenant_id' => $this->tenant->id, 'price' => 100, 'price_a' => 90, 'cost_price' => 40,
        ]);
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $shelf->id,
            'product_id' => $this->product->id, 'quantity' => 100,
        ]);
    }

    private function sell(User $as, array $line)
    {
        return $this->actingAs($as)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 2] + $line],
        ]);
    }

    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'orders' => $rows('orders'), 'order_items' => $rows('order_items'),
            'ledger' => $rows('ledger_entries'), 'inventory' => $rows('inventory'),
        ];
    }

    public function test_store_staff_cannot_set_a_line_price_and_nothing_is_written(): void
    {
        $before = $this->snapshot();

        $this->sell($this->staff, ['unit_price' => 50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.unit_price');

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_store_staff_still_sells_at_the_customers_tier_price(): void
    {
        $id = $this->sell($this->staff, [])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'unit_price' => 90]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'total' => 180]);
    }

    public function test_a_manager_can_set_a_line_price(): void
    {
        $id = $this->sell($this->manager, ['unit_price' => 85])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'unit_price' => 85]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'total' => 170]);
        $this->assertEquals(170, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_the_owner_can_set_a_line_price(): void
    {
        $id = $this->sell($this->admin, ['unit_price' => 110])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'unit_price' => 110]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'total' => 220]);
    }

    public function test_without_a_price_the_owner_gets_the_customers_tier_price(): void
    {
        $id = $this->sell($this->admin, [])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'unit_price' => 90]);
    }
}
