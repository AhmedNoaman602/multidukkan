<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $storeA;
    private Store $storeB;
    private User $admin;
    private User $managerA;
    private User $managerA2;
    private User $managerB;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->storeA = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->storeB = Store::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->managerA = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
            'role'      => 'store_manager',
        ]);
        $this->managerA2 = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
            'role'      => 'store_manager',
        ]);
        $this->managerB = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeB->id,
            'role'      => 'store_manager',
        ]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
            'role'      => 'store_staff',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'category'     => 'RENT',
            'amount'       => 1500,
            'description'  => 'Monthly office rent',
            'expense_date' => now()->toDateString(),
        ], $overrides);
    }

    private function makeExpense(array $overrides = []): Expense
    {
        return Expense::factory()->create(array_merge([
            'tenant_id'  => $this->tenant->id,
            'store_id'   => $this->storeA->id,
            'created_by' => $this->managerA->id,
        ], $overrides));
    }

    // ───────────────────────────── Validation ─────────────────────────────

    public function test_future_expense_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload(['expense_date' => now()->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_date');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload(['amount' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload(['amount' => -50]))
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_amount_over_column_maximum_is_rejected_not_500(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload(['amount' => 100000000]))
            ->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_invalid_category_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload(['category' => 'FOOD']))
            ->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_description_over_255_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload(['description' => str_repeat('a', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_miscellaneous_requires_description_on_create(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload(['category' => 'MISCELLANEOUS', 'description' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('description');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_miscellaneous_requires_description_on_update_to_misc(): void
    {
        // Stored as RENT with no description; PATCH to MISCELLANEOUS without a
        // description must fail on the resulting state, not the payload alone.
        $expense = $this->makeExpense(['category' => 'RENT', 'description' => null]);

        $this->actingAs($this->managerA)
            ->patchJson("/api/expenses/{$expense->id}", ['category' => 'MISCELLANEOUS'])
            ->assertStatus(422)->assertJsonValidationErrors('description');

        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'category' => 'RENT']);
    }

    // ─────────────────────────── Authorization (role) ───────────────────────────

    public function test_store_staff_is_blocked_on_every_endpoint(): void
    {
        $expense = $this->makeExpense();

        $this->actingAs($this->staff)->getJson('/api/expenses')->assertStatus(403);
        $this->actingAs($this->staff)->postJson('/api/expenses', $this->validPayload())->assertStatus(403);
        $this->actingAs($this->staff)->patchJson("/api/expenses/{$expense->id}", ['amount' => 20])->assertStatus(403);
        $this->actingAs($this->staff)->deleteJson("/api/expenses/{$expense->id}")->assertStatus(403);

        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => $expense->amount]);
    }

    public function test_admin_can_create_update_and_delete_any_tenant_expense(): void
    {
        // An expense created by managerA, in storeA.
        $expense = $this->makeExpense(['amount' => 100]);

        $this->actingAs($this->admin)
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 250])
            ->assertOk();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 250]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertOk();
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    public function test_manager_can_update_and_delete_own_store_self_created_expense(): void
    {
        $expense = $this->makeExpense(['created_by' => $this->managerA->id, 'amount' => 100]);

        $this->actingAs($this->managerA)
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 300])
            ->assertOk();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 300]);

        $this->actingAs($this->managerA)
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertOk();
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    // ─────────────────── Authorization (cross-store & tenant-wide) ───────────────────

    /** Case A: a manager cannot touch another store's expense. */
    public function test_case_a_manager_cannot_update_another_stores_expense(): void
    {
        $expenseB = $this->makeExpense(['store_id' => $this->storeB->id, 'created_by' => $this->managerB->id, 'amount' => 100]);

        $this->actingAs($this->managerA)
            ->patchJson("/api/expenses/{$expenseB->id}", ['amount' => 999])
            ->assertStatus(403);

        $this->assertDatabaseHas('expenses', ['id' => $expenseB->id, 'amount' => 100]);
    }

    /** Case B: a manager cannot mutate a tenant-wide (NULL-store) expense. */
    public function test_case_b_manager_cannot_mutate_tenant_wide_expense(): void
    {
        $tenantWide = $this->makeExpense(['store_id' => null, 'created_by' => $this->admin->id, 'amount' => 100]);

        $this->actingAs($this->managerA)
            ->patchJson("/api/expenses/{$tenantWide->id}", ['amount' => 999])
            ->assertStatus(403);

        $this->actingAs($this->managerA)
            ->deleteJson("/api/expenses/{$tenantWide->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('expenses', ['id' => $tenantWide->id, 'amount' => 100]);
        $this->assertNotSoftDeleted('expenses', ['id' => $tenantWide->id]);
    }

    /** Case C: after the store is deleted, the expense survives as tenant-wide and becomes admin-only. */
    public function test_case_c_store_deletion_makes_expense_tenant_level_and_admin_only(): void
    {
        $expense = $this->makeExpense(['store_id' => $this->storeA->id, 'created_by' => $this->managerA->id, 'amount' => 100]);

        // storeA has no warehouses/unpaid orders and is not the only store, so it can be deleted.
        $this->storeA->delete();

        // Expense survives; store_id is nulled (historical tenant-level row).
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'store_id' => null]);

        // The original manager (store now null) can no longer mutate it.
        $this->managerA->refresh();
        $this->actingAs($this->managerA)
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 999])
            ->assertStatus(403);
        $this->actingAs($this->managerA)
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 100]);

        // A tenant admin still can.
        $this->actingAs($this->admin)
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 777])
            ->assertOk();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 777]);
    }

    public function test_manager_cannot_mutate_another_managers_same_store_expense(): void
    {
        // Created by managerA2, same store as managerA.
        $expense = $this->makeExpense(['created_by' => $this->managerA2->id, 'amount' => 100]);

        $this->actingAs($this->managerA)
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 999])
            ->assertStatus(403);

        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => 100]);
    }

    // ───────────────────────── Manager store-scoping (index) ─────────────────────────

    public function test_manager_index_excludes_other_stores_and_tenant_wide_rows(): void
    {
        $ownStore    = $this->makeExpense(['store_id' => $this->storeA->id, 'created_by' => $this->managerA->id]);
        $otherStore  = $this->makeExpense(['store_id' => $this->storeB->id, 'created_by' => $this->managerB->id]);
        $tenantWide  = $this->makeExpense(['store_id' => null, 'created_by' => $this->admin->id]);

        $ids = collect($this->actingAs($this->managerA)->getJson('/api/expenses')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($ownStore->id));
        $this->assertFalse($ids->contains($otherStore->id));
        $this->assertFalse($ids->contains($tenantWide->id));

        // Admin sees all three.
        $adminIds = collect($this->actingAs($this->admin)->getJson('/api/expenses')->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing(
            [$ownStore->id, $otherStore->id, $tenantWide->id],
            $adminIds->all()
        );
    }

    // ─────────────────────── Server-controlled fields on create ───────────────────────

    public function test_created_by_is_taken_from_the_authenticated_user_not_the_payload(): void
    {
        $response = $this->actingAs($this->managerA)
            ->postJson('/api/expenses', $this->validPayload(['created_by' => $this->admin->id]))
            ->assertStatus(201);

        $this->assertDatabaseHas('expenses', [
            'id'              => $response->json('id'),
            'created_by'      => $this->managerA->id,
            'created_by_name' => $this->managerA->name,
        ]);
    }

    public function test_manager_store_id_is_forced_to_their_own_store(): void
    {
        $response = $this->actingAs($this->managerA)
            ->postJson('/api/expenses', $this->validPayload(['store_id' => $this->storeB->id]))
            ->assertStatus(201);

        $this->assertDatabaseHas('expenses', [
            'id'       => $response->json('id'),
            'store_id' => $this->storeA->id, // forced to own store, not the payload's storeB
        ]);
    }

    public function test_admin_can_create_a_tenant_wide_expense_with_null_store(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/expenses', $this->validPayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('expenses', ['id' => $response->json('id'), 'store_id' => null]);
    }

    // ───────────────────────────── Tenant isolation ─────────────────────────────

    public function test_tenant_cannot_touch_another_tenants_expense(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherStore  = Store::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherAdmin  = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $foreign = Expense::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'store_id'   => $otherStore->id,
            'created_by' => $otherAdmin->id,
            'amount'     => 100,
        ]);

        // 404, not 403: the global tenant scope hides the row from route-model binding,
        // so the request never reaches the controller's check. A 403 would confirm the
        // ID exists in another tenant.
        $this->actingAs($this->admin)
            ->patchJson("/api/expenses/{$foreign->id}", ['amount' => 999])
            ->assertStatus(404);
        $this->actingAs($this->admin)
            ->deleteJson("/api/expenses/{$foreign->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('expenses', ['id' => $foreign->id, 'amount' => 100]);
        $this->assertNotSoftDeleted('expenses', ['id' => $foreign->id]);
    }

    // ───────────────────────────── Soft delete ─────────────────────────────

    public function test_destroy_soft_deletes_and_hides_from_index_and_404s_on_repeat(): void
    {
        $expense = $this->makeExpense(['created_by' => $this->managerA->id]);

        $this->actingAs($this->managerA)->deleteJson("/api/expenses/{$expense->id}")->assertOk();
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);

        $ids = collect($this->actingAs($this->managerA)->getJson('/api/expenses')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($expense->id));

        // Route-model binding excludes trashed rows -> 404 (no withTrashed on the route).
        $this->actingAs($this->managerA)->deleteJson("/api/expenses/{$expense->id}")->assertStatus(404);
    }

    // ───────────────────────────── Filtering & stats ─────────────────────────────

    public function test_category_and_date_filters_and_stats_reflect_the_filtered_set(): void
    {
        $this->makeExpense(['category' => 'RENT',      'amount' => 1000, 'expense_date' => '2026-07-10']);
        $this->makeExpense(['category' => 'SALARIES',  'amount' => 3000, 'expense_date' => '2026-07-20']);
        $this->makeExpense(['category' => 'RENT',      'amount' => 500,  'expense_date' => '2026-06-15']);

        // category = RENT
        $byCategory = $this->actingAs($this->admin)->getJson('/api/expenses?category=RENT');
        $this->assertCount(2, $byCategory->json('data'));
        $this->assertEquals(1500.0, $byCategory->json('stats.total_amount'));

        // July range only
        $byDate = $this->actingAs($this->admin)->getJson('/api/expenses?date_from=2026-07-01&date_to=2026-07-31');
        $this->assertCount(2, $byDate->json('data'));
        $this->assertEquals(4000.0, $byDate->json('stats.total_amount'));
    }

    // ───────────────────────────── Audit logging ─────────────────────────────

    public function test_create_update_delete_each_write_exactly_one_audit_row(): void
    {
        // create
        $response = $this->actingAs($this->managerA)
            ->postJson('/api/expenses', $this->validPayload())
            ->assertStatus(201);
        $id = $response->json('id');

        $this->assertEquals(1, AuditLog::where('auditable_type', Expense::class)
            ->where('auditable_id', $id)->where('action', 'created')->count());

        // update
        $this->actingAs($this->managerA)->patchJson("/api/expenses/{$id}", ['amount' => 2000])->assertOk();

        $updateRows = AuditLog::where('auditable_type', Expense::class)
            ->where('auditable_id', $id)->where('action', 'updated')->get();
        $this->assertCount(1, $updateRows);
        $this->assertNotEmpty($updateRows->first()->changes); // diff recorded

        // delete
        $this->actingAs($this->managerA)->deleteJson("/api/expenses/{$id}")->assertOk();

        $this->assertEquals(1, AuditLog::where('auditable_type', Expense::class)
            ->where('auditable_id', $id)->where('action', 'deleted')->count());
    }
}
