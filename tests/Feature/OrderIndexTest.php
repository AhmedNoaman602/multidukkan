<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    private function order(string $orderDate): Order
    {
        return Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'created_by'  => $this->admin->id,
            'order_date'  => $orderDate,
            'total'       => 100,
        ]);
    }

    public function test_index_derives_distinct_months_newest_first(): void
    {
        $this->order('2026-09-01');
        $this->order('2026-09-28');
        $this->order('2026-03-11');
        $this->order('2025-12-02');

        $months = $this->actingAs($this->admin)
            ->getJson('/api/orders')
            ->assertStatus(200)
            ->json('months');

        $this->assertSame([
            ['year' => 2026, 'month' => 9],
            ['year' => 2026, 'month' => 3],
            ['year' => 2025, 'month' => 12],
        ], $months);
    }

    public function test_index_derives_distinct_years_newest_first(): void
    {
        $this->order('2026-09-01');
        $this->order('2026-03-11');
        $this->order('2025-12-02');
        $this->order('2024-01-30');

        $years = $this->actingAs($this->admin)
            ->getJson('/api/orders')
            ->assertStatus(200)
            ->json('years');

        $this->assertSame([2026, 2025, 2024], $years);
    }

    public function test_index_does_not_leak_another_tenants_months(): void
    {
        $this->order('2026-09-01');

        $otherTenant  = Tenant::factory()->create();
        $otherStore   = Store::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);

        Order::factory()->create([
            'tenant_id'   => $otherTenant->id,
            'store_id'    => $otherStore->id,
            'customer_id' => $otherCustomer->id,
            'order_date'  => '2019-05-04',
            'total'       => 100,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/orders')
            ->assertStatus(200);

        $this->assertSame([2026], $response->json('years'));
    }
}
