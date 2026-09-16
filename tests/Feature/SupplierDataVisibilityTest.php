<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierDataVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private User $manager;
    private User $staff;
    private Supplier $supplier;
    private PurchaseOrder $purchaseOrder;
    private SupplierPayment $supplierPayment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'role'      => 'store_manager',
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'role'      => 'store_staff',
        ]);

        $this->supplier = Supplier::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->purchaseOrder = PurchaseOrder::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'total'       => 500,
        ]);

        $this->supplierPayment = SupplierPayment::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $this->purchaseOrder->id,
            'amount'            => 200,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function guardedRoutes(): array
    {
        return [
            'supplier index'      => '/api/suppliers',
            'supplier show'       => "/api/suppliers/{$this->supplier->id}",
            'supplier products'   => "/api/suppliers/{$this->supplier->id}/products",
            'supplier summary'    => "/api/suppliers/{$this->supplier->id}/summary",
            'purchase order list' => '/api/purchase-orders',
            'purchase order show' => "/api/purchase-orders/{$this->purchaseOrder->id}",
            'supplier payments'   => '/api/supplier-payments',
        ];
    }

    public function test_store_staff_is_denied_every_supplier_and_purchasing_route(): void
    {
        foreach ($this->guardedRoutes() as $label => $url) {
            $this->actingAs($this->staff)
                ->getJson($url)
                ->assertStatus(403, "store_staff should be denied: {$label}");
        }
    }

    public function test_store_manager_is_denied_every_supplier_and_purchasing_route(): void
    {
        foreach ($this->guardedRoutes() as $label => $url) {
            $this->actingAs($this->manager)
                ->getJson($url)
                ->assertStatus(403, "store_manager should be denied: {$label}");
        }
    }

    public function test_tenant_admin_can_read_every_supplier_and_purchasing_route(): void
    {
        foreach ($this->guardedRoutes() as $label => $url) {
            $this->actingAs($this->admin)
                ->getJson($url)
                ->assertStatus(200, "tenant_admin should be allowed: {$label}");
        }
    }

    public function test_denied_roles_receive_no_supplier_or_cost_payload(): void
    {
        foreach ([$this->staff, $this->manager] as $user) {
            $body = $this->actingAs($user)
                ->getJson("/api/suppliers/{$this->supplier->id}/products")
                ->assertStatus(403)
                ->json();

            $this->assertArrayNotHasKey('data', $body);
            $this->assertStringNotContainsString($this->supplier->name, json_encode($body));
        }
    }
}
