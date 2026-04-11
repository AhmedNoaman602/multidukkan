<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Warehouse;
use App\Models\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PriceTierTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $user;
    private Product $product;
    private Warehouse $warehouse;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant']);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Test Store',
            'address'   => 'Cairo',
            'phone'     => '01000000000',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'name'      => 'Admin',
            'email'     => 'admin@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'tenant_admin',
        ]);

        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        
         $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Test Product',
            'sku'       => 'SKU-001',
            'price'     => 100,
            'price_a'   => 90,
            'price_b'   => 80,
            'price_c'   => 70,
            'price_d'   => 60,
            'price_e'   => 50,
            'unit'      => 'pcs',
        ]);

        $this->inventory = Inventory::factory()->create([
       'tenant_id'    => $this->tenant->id,
    'warehouse_id' => $this->warehouse->id,
    'product_id'   => $this->product->id,
    'quantity'     => 100,
    'threshold'    => 10,
    ]);

       
    }

    private function createOrderForCustomer(Customer $customer): array
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/orders', [
                'store_id'    => $this->store->id,
                'customer_id' => $customer->id,
                'items'       => [
                    ['product_id' => $this->product->id, 'quantity' => 1,'warehouse_id' => $this->warehouse->id,],
                ],
            ]);

        return $response->json();
    }

    public function test_default_tier_uses_base_price(): void
    {
        $customer = Customer::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Default Customer',
            'phone'      => '01000000001',
            'price_tier' => 'default',
        ]);

        $order = $this->createOrderForCustomer($customer);

        $this->assertEquals(100, $order['items'][0]['unit_price']);
    }

    public function test_tier_a_uses_price_a(): void
    {
        $customer = Customer::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Tier A Customer',
            'phone'      => '01000000002',
            'price_tier' => 'a',
        ]);

        $order = $this->createOrderForCustomer($customer);

        $this->assertEquals(90, $order['items'][0]['unit_price']);
    }

    public function test_tier_b_uses_price_b(): void
    {
        $customer = Customer::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Tier B Customer',
            'phone'      => '01000000003',
            'price_tier' => 'b',
        ]);

        $order = $this->createOrderForCustomer($customer);

        $this->assertEquals(80, $order['items'][0]['unit_price']);
    }

    public function test_tier_falls_back_to_base_price_when_tier_price_is_null(): void
    {
        $productNoTiers = Product::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'No Tier Product',
            'sku'       => 'SKU-002',
            'price'     => 200,
            'unit'      => 'pcs',
        ]);

        $this->inventory = Inventory::factory()->create([
       'tenant_id'    => $this->tenant->id,
    'warehouse_id' => $this->warehouse->id,
    'product_id'   => $productNoTiers->id,
    'quantity'     => 100,
    'threshold'    => 10,
    ]);

        $customer = Customer::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Tier A Customer',
            'phone'      => '01000000004',
            'price_tier' => 'a',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/orders', [
                'store_id'    => $this->store->id,
                'customer_id' => $customer->id,
                'items'       => [
                    ['product_id' => $productNoTiers->id, 'quantity' => 1,'warehouse_id' => $this->warehouse->id,],
                ],
            ]);

        $this->assertEquals(200, $response->json('items.0.unit_price'));
    }
}