<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Supplier pickers (purchase orders, product forms) search on the server, a page at a time.
class SupplierLookupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin  = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'role'      => 'store_staff',
        ]);
    }

    private function suppliers(int $count): void
    {
        foreach (range(1, $count) as $n) {
            Supplier::factory()->create([
                'tenant_id' => $this->tenant->id,
                'code'      => "S-{$n}",
                'name'      => "Supplier {$n}",
            ]);
        }
    }

    public function test_a_supplier_past_the_first_page_is_found_by_search(): void
    {
        $this->suppliers(22);

        $this->actingAs($this->admin)->getJson('/api/suppliers/lookup?search=Supplier 22')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'S-22');
    }

    public function test_search_matches_the_name_phone_or_code(): void
    {
        $supplier = Supplier::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'S-9', 'name' => 'Nile Hardware', 'phone' => '01233334444',
        ]);

        foreach (['nile', '3333444', 's-9'] as $search) {
            $this->actingAs($this->admin)->getJson('/api/suppliers/lookup?search=' . $search)
                ->assertOk()
                ->assertJsonPath('data.0.id', $supplier->id);
        }
    }

    public function test_results_come_twenty_a_page_in_code_order_with_the_total(): void
    {
        $this->suppliers(25);

        $this->actingAs($this->admin)->getJson('/api/suppliers/lookup')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.code', 'S-1')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->admin)->getJson('/api/suppliers/lookup?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.code', 'S-21');
    }

    public function test_only_the_fields_a_picker_needs_are_returned(): void
    {
        $this->suppliers(1);

        $row = $this->actingAs($this->admin)->getJson('/api/suppliers/lookup')->assertOk()->json('data.0');

        $this->assertSame(['id', 'code', 'name', 'phone'], array_keys($row));
    }

    public function test_another_tenants_suppliers_are_never_returned(): void
    {
        $own = Supplier::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'S-1', 'name' => 'Same Name']);
        Supplier::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'code' => 'S-1', 'name' => 'Same Name']);

        $this->actingAs($this->admin)->getJson('/api/suppliers/lookup?search=Same Name')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_store_staff_cannot_look_up_suppliers(): void
    {
        $this->suppliers(1);

        $this->actingAs($this->staff)->getJson('/api/suppliers/lookup')->assertForbidden();

        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_an_overlong_search_is_rejected(): void
    {
        $this->suppliers(1);

        $this->actingAs($this->admin)->getJson('/api/suppliers/lookup?search=' . str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');

        $this->assertDatabaseCount('suppliers', 1);
    }
}
