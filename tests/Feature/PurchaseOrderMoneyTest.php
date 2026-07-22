<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SupplierPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Supplier $supplier;
    protected Product $product;
    protected User $user;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 10,
        ]);
    }

    private function createPurchaseOrder(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/purchase-orders', array_merge([
            'supplier_id' => $this->supplier->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ], $overrides));
    }

    // ─────────────────────────────────────────
    // 1. Invoice number generator
    // ─────────────────────────────────────────
    public function test_invoice_number_rolls_over_past_999_without_collision(): void
    {
        PurchaseOrder::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'supplier_id'    => $this->supplier->id,
            'invoice_number' => now()->year . '-999',
            'total'          => 100,
        ]);

        $response = $this->createPurchaseOrder()->assertStatus(201);

        $this->assertEquals(now()->year . '-1000', $response->json('data.invoice_number'));
    }

    // ─────────────────────────────────────────
    // Stock movements are labeled PURCHASE_IN/PURCHASE_OUT, not SALE/RETURN
    // (those are for customer orders — reusing them here mislabels PO events)
    // ─────────────────────────────────────────
    public function test_creating_a_purchase_order_logs_a_purchase_in_stock_movement(): void
    {
        $this->createPurchaseOrder()->assertStatus(201);

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_type' => \App\Models\PurchaseOrder::class,
            'type'           => 'PURCHASE_IN',
        ]);
        $this->assertDatabaseMissing('inventory_transactions', ['type' => 'RETURN']);
    }

    public function test_cancelling_a_purchase_order_logs_a_purchase_out_stock_movement(): void
    {
        $poId = $this->createPurchaseOrder()->assertStatus(201)->json('data.id');

        $this->actingAs($this->user)->deleteJson("/api/purchase-orders/{$poId}")->assertStatus(200);

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_type' => \App\Models\PurchaseOrder::class,
            'reference_id'   => $poId,
            'type'           => 'PURCHASE_OUT',
        ]);
        $this->assertDatabaseMissing('inventory_transactions', ['type' => 'SALE']);
    }

    // ─────────────────────────────────────────
    // 2. PurchaseOrderResource reads the stored total
    // ─────────────────────────────────────────
    public function test_resource_total_matches_stored_total(): void
    {
        $response = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 50],
            ],
        ])->assertStatus(201);

        $poId = $response->json('data.id');

        $this->assertEquals(150, $response->json('data.total'));
        $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'total' => 150]);
    }

    // ─────────────────────────────────────────
    // 2b. Updating a purchase order only touches notes, never total
    // ─────────────────────────────────────────
    public function test_update_purchase_order_changes_notes_but_not_total(): void
    {
        $poId = $this->createPurchaseOrder()->assertStatus(201)->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/purchase-orders/{$poId}", [
                'notes' => 'Corrected typo',
                'total' => 999999, // not in UpdatePurchaseOrderRequest's rules — must be ignored
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.notes', 'Corrected typo');

        $this->assertDatabaseHas('purchase_orders', [
            'id'    => $poId,
            'notes' => 'Corrected typo',
            'total' => 200, // unchanged — 2 x 100 from createPurchaseOrder()'s default items
        ]);
    }

    public function test_cannot_update_a_cancelled_purchase_order(): void
    {
        $poId = $this->createPurchaseOrder()->assertStatus(201)->json('data.id');

        $this->actingAs($this->user)->deleteJson("/api/purchase-orders/{$poId}")->assertStatus(200);

        $this->actingAs($this->user)
            ->patchJson("/api/purchase-orders/{$poId}", ['notes' => 'too late'])
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────
    // 3. Cancelling reverses purchase_orders.total, not a re-derived items sum
    // ─────────────────────────────────────────
    public function test_cancel_purchase_order_reverses_the_stored_total(): void
    {
        $response = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201);

        $poId = $response->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/purchase-orders/{$poId}")
            ->assertStatus(200);

        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => PurchaseOrder::class,
            'reference_id'   => $poId,
            'type'           => 'PURCHASE_REVERSAL',
            'amount'         => 200,
        ]);

        $this->assertSoftDeleted('purchase_orders', ['id' => $poId]);
    }

    public function test_cannot_cancel_a_purchase_order_with_payments(): void
    {
        $poId = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->user)->postJson('/api/supplier-payments', [
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $poId,
            'amount'            => 50,
            'method'            => 'cash',
        ])->assertStatus(201);

        $this->actingAs($this->user)
            ->deleteJson("/api/purchase-orders/{$poId}")
            ->assertStatus(422);

        $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'deleted_at' => null]);
    }

    public function test_reversing_a_supplier_payment_unblocks_cancellation(): void
    {
        $poId = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $paymentId = $this->actingAs($this->user)->postJson('/api/supplier-payments', [
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $poId,
            'amount'            => 50,
            'method'            => 'cash',
        ])->assertStatus(201)->json('payments.0.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/supplier-payments/{$paymentId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('supplier_payments', ['id' => $paymentId]);
        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => 'supplier_payment',
            'reference_id'   => $paymentId,
            'type'           => 'SUPPLIER_PAYMENT_REVERSAL',
            'direction'      => 'debit',
            'amount'         => 50,
        ]);

        // Now that the payment is gone, cancellation is unblocked again.
        $this->actingAs($this->user)
            ->deleteJson("/api/purchase-orders/{$poId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('purchase_orders', ['id' => $poId]);
    }

    public function test_repeat_reversal_of_the_same_supplier_payment_is_rejected(): void
    {
        $poId = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $paymentId = $this->actingAs($this->user)->postJson('/api/supplier-payments', [
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $poId,
            'amount'            => 50,
            'method'            => 'cash',
        ])->assertStatus(201)->json('payments.0.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/supplier-payments/{$paymentId}")
            ->assertStatus(200);

        // Second attempt: the payment is already gone, and must be rejected cleanly
        // instead of posting a second reversal or silently no-op-succeeding.
        $this->actingAs($this->user)
            ->deleteJson("/api/supplier-payments/{$paymentId}")
            ->assertStatus(404);

        $this->assertEquals(1, \App\Models\LedgerEntry::where([
            'reference_type' => 'supplier_payment',
            'reference_id'   => $paymentId,
            'type'           => 'SUPPLIER_PAYMENT_REVERSAL',
        ])->count());
    }

    public function test_concurrent_reversal_of_the_same_supplier_payment_is_rejected(): void
    {
        $poId = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $paymentId = $this->actingAs($this->user)->postJson('/api/supplier-payments', [
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $poId,
            'amount'            => 50,
            'method'            => 'cash',
        ])->assertStatus(201)->json('payments.0.id');

        // Simulate two concurrent DELETE requests: both resolve their own copy of the
        // payment via route-model binding *before* either transaction starts.
        $requestA = SupplierPayment::find($paymentId);
        $requestB = SupplierPayment::find($paymentId);

        $service = $this->app->make(SupplierPaymentService::class);

        $service->reversePayment($requestA, $this->user);

        $this->assertDatabaseMissing('supplier_payments', ['id' => $paymentId]);
        $this->assertEquals(1, \App\Models\LedgerEntry::where([
            'reference_type' => 'supplier_payment',
            'reference_id'   => $paymentId,
            'type'           => 'SUPPLIER_PAYMENT_REVERSAL',
        ])->count());

        $this->expectException(\InvalidArgumentException::class);
        $service->reversePayment($requestB, $this->user);
    }

    public function test_supplier_summary_includes_individual_payments(): void
    {
        $poId = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $paymentId = $this->actingAs($this->user)->postJson('/api/supplier-payments', [
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $poId,
            'amount'            => 50,
            'method'            => 'cash',
        ])->assertStatus(201)->json('payments.0.id');

        $response = $this->actingAs($this->user)
            ->getJson("/api/suppliers/{$this->supplier->id}/summary")
            ->assertStatus(200);

        $response->assertJsonFragment([
            'id'     => $paymentId,
            'amount' => '50.00',
            'method' => 'cash',
        ]);
    }

    // ─────────────────────────────────────────
    // settledAmount()/isSettled() + audit log parity with Order
    // ─────────────────────────────────────────
    public function test_purchase_order_settled_amount_and_is_settled(): void
    {
        $poId = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $purchaseOrder = PurchaseOrder::find($poId);
        $this->assertEquals(0, $purchaseOrder->settledAmount());
        $this->assertFalse($purchaseOrder->isSettled());

        $this->actingAs($this->user)->postJson('/api/supplier-payments', [
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $poId,
            'amount'            => 200,
            'method'            => 'cash',
        ])->assertStatus(201);

        $purchaseOrder->refresh();
        $this->assertEquals(200, $purchaseOrder->settledAmount());
        $this->assertTrue($purchaseOrder->isSettled());
    }

    public function test_updating_purchase_order_writes_an_audit_log_entry(): void
    {
        $poId = $this->createPurchaseOrder()->assertStatus(201)->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/purchase-orders/{$poId}", ['notes' => 'Updated note'])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PurchaseOrder::class,
            'auditable_id'   => $poId,
            'action'         => 'updated',
        ]);
    }

    // ─────────────────────────────────────────
    // 4. SupplierPaymentService bulk distribution uses PurchaseOrder::scopeWhereUnpaid
    // ─────────────────────────────────────────
    public function test_supplier_payment_distributes_across_unpaid_purchase_orders_oldest_first(): void
    {
        $older = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $newer = $this->createPurchaseOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id, 'unit_price' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        PurchaseOrder::whereIn('id', [$older, $newer])->update(['created_at' => now()->subDay()]);
        PurchaseOrder::where('id', $older)->update(['created_at' => now()->subDays(2)]);

        $this->actingAs($this->user)->postJson('/api/supplier-payments', [
            'supplier_id' => $this->supplier->id,
            'amount'      => 100,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertDatabaseHas('supplier_payments', [
            'purchase_order_id' => $older,
            'amount'            => 100,
        ]);
        $this->assertDatabaseMissing('supplier_payments', [
            'purchase_order_id' => $newer,
        ]);
    }
}
