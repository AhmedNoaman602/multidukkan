<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// MUL2-53: pickers search customers on the server, a page at a time, instead of loading the first page only.
class CustomerLookupTest extends TestCase
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

    private function customers(int $count): void
    {
        foreach (range(1, $count) as $n) {
            Customer::factory()->create([
                'tenant_id' => $this->tenant->id,
                'code'      => "C-{$n}",
                'name'      => "Customer {$n}",
            ]);
        }
    }

    public function test_a_customer_past_the_first_page_is_found_by_search(): void
    {
        $this->customers(20);

        $this->actingAs($this->admin)->getJson('/api/customers/lookup?search=Customer 20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'C-20');
    }

    public function test_search_matches_the_name_phone_or_code(): void
    {
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'C-7', 'name' => 'Hassan Ali', 'phone' => '01055554444',
        ]);

        foreach (['hassan', '5555444', 'c-7'] as $search) {
            $this->actingAs($this->admin)->getJson('/api/customers/lookup?search=' . $search)
                ->assertOk()
                ->assertJsonPath('data.0.id', $customer->id);
        }
    }

    public function test_results_come_twenty_a_page_in_code_order(): void
    {
        $this->customers(25);

        $this->actingAs($this->admin)->getJson('/api/customers/lookup')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.code', 'C-1')
            ->assertJsonPath('data.19.code', 'C-20')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->admin)->getJson('/api/customers/lookup?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.code', 'C-21');
    }

    public function test_only_the_fields_a_picker_needs_are_returned(): void
    {
        $this->customers(1);

        $row = $this->actingAs($this->admin)->getJson('/api/customers/lookup')->assertOk()->json('data.0');

        $this->assertSame(['id', 'code', 'name', 'phone', 'price_tier'], array_keys($row));
    }

    public function test_other_tenants_and_the_walk_in_customer_are_never_returned(): void
    {
        $own = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'C-1', 'name' => 'Same Name']);
        Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Same Name walk-in', 'is_walk_in' => true]);
        Customer::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'code' => 'C-1', 'name' => 'Same Name']);

        $this->actingAs($this->admin)->getJson('/api/customers/lookup?search=Same Name')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_store_staff_can_look_up_customers_for_a_sale(): void
    {
        $this->customers(3);

        $this->actingAs($this->staff)->getJson('/api/customers/lookup?search=Customer 2')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'C-2');
    }

    public function test_an_overlong_search_is_rejected(): void
    {
        $this->customers(1);

        $this->actingAs($this->admin)->getJson('/api/customers/lookup?search=' . str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');

        $this->assertDatabaseCount('customers', 1);
    }
}
