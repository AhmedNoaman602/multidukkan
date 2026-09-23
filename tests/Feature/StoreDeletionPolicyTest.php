<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreDeletionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Store $otherStore;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->otherStore = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => null,
            'role' => 'tenant_admin',
        ]);
    }

    private function deleteStore(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['X-Locale' => 'en'])
            ->deleteJson("/api/stores/{$this->store->id}");
    }

    private function makeOrder(): Order
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        return Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'total' => 100,
        ]);
    }

    private function makeLedgerEntry(): LedgerEntry
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        return LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'type' => 'ORDER_CHARGE',
            'direction' => 'debit',
            'amount' => 100,
        ]);
    }

    public function test_store_with_order_history_cannot_be_deleted(): void
    {
        $order = $this->makeOrder();

        $this->deleteStore()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete a store with existing orders');

        $this->assertDatabaseHas('stores', ['id' => $this->store->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'store_id' => $this->store->id]);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_store_with_soft_deleted_order_history_cannot_be_deleted(): void
    {
        $order = $this->makeOrder();
        $order->delete();

        $this->deleteStore()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete a store with existing orders');

        $this->assertDatabaseHas('stores', ['id' => $this->store->id]);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_store_with_ledger_history_but_no_orders_cannot_be_deleted(): void
    {
        $entry = $this->makeLedgerEntry();

        $this->deleteStore()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete a store with financial history');

        $this->assertDatabaseHas('stores', ['id' => $this->store->id]);
        $this->assertDatabaseHas('ledger_entries', ['id' => $entry->id, 'store_id' => $this->store->id]);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_store_with_assigned_users_cannot_be_deleted(): void
    {
        $manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role' => 'store_manager',
        ]);

        $this->deleteStore()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete a store that still has users assigned. Reassign them first');

        $this->assertDatabaseHas('stores', ['id' => $this->store->id]);
        $this->assertDatabaseHas('users', ['id' => $manager->id, 'store_id' => $this->store->id]);
    }

    public function test_store_with_warehouses_cannot_be_deleted(): void
    {
        $warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
        ]);

        $this->deleteStore()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete a store with existing warehouses. Remove warehouses first');

        $this->assertDatabaseHas('stores', ['id' => $this->store->id]);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }

    public function test_cannot_delete_the_only_store(): void
    {
        $tenant = Tenant::factory()->create();
        $onlyStore = Store::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'store_id' => null,
            'role' => 'tenant_admin',
        ]);

        $this->actingAs($admin)
            ->withHeaders(['X-Locale' => 'en'])
            ->deleteJson("/api/stores/{$onlyStore->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete the only store. At least one store is required');

        $this->assertDatabaseHas('stores', ['id' => $onlyStore->id]);
    }

    public function test_store_without_history_users_or_warehouses_can_be_deleted(): void
    {
        $this->deleteStore()
            ->assertStatus(200)
            ->assertJsonPath('message', 'Store deleted successfully');

        $this->assertDatabaseMissing('stores', ['id' => $this->store->id]);
        $this->assertDatabaseHas('stores', ['id' => $this->otherStore->id]);
    }
}
