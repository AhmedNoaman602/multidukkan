<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private Expense $expenseA;
    private Expense $expenseB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $storeA = Store::factory()->create(['tenant_id' => $this->tenantA->id]);
        $storeB = Store::factory()->create(['tenant_id' => $this->tenantB->id]);

        $this->adminA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->expenseA = Expense::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'store_id'  => $storeA->id,
        ]);
        $this->expenseB = Expense::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'store_id'  => $storeB->id,
        ]);
    }

    public function test_queries_return_only_the_authenticated_tenants_rows(): void
    {
        $this->actingAs($this->adminA);

        $ids = Expense::pluck('id');

        $this->assertTrue($ids->contains($this->expenseA->id));
        $this->assertFalse($ids->contains($this->expenseB->id));
    }

    public function test_find_cannot_reach_another_tenants_row(): void
    {
        $this->actingAs($this->adminA);

        $this->assertNull(Expense::find($this->expenseB->id));
        $this->assertNotNull(Expense::find($this->expenseA->id));
    }

    /** Both rows still exist — the scope hides them, it does not delete them. */
    public function test_the_scope_can_be_lifted_deliberately(): void
    {
        $this->actingAs($this->adminA);

        $this->assertCount(2, Expense::withoutGlobalScope('tenant')->get());
    }

    public function test_tenant_id_is_stamped_on_create_when_omitted(): void
    {
        $this->actingAs($this->adminA);

        $expense = Expense::create([
            'category'     => 'RENT',
            'amount'       => 500,
            'description'  => 'No tenant_id passed',
            'expense_date' => now()->toDateString(),
        ]);

        $this->assertSame($this->tenantA->id, $expense->tenant_id);
    }

    /** Existing code passes tenant_id explicitly; the hook must never overwrite it. */
    public function test_an_explicit_tenant_id_is_never_overwritten(): void
    {
        $this->actingAs($this->adminA);

        $expense = Expense::create([
            'tenant_id'    => $this->tenantB->id,
            'category'     => 'RENT',
            'amount'       => 500,
            'description'  => 'Explicit foreign tenant',
            'expense_date' => now()->toDateString(),
        ]);

        $this->assertSame($this->tenantB->id, $expense->tenant_id);
    }

    /** Seeders, queued jobs and artisan commands have no authenticated user. */
    public function test_the_scope_no_ops_when_nobody_is_authenticated(): void
    {
        $this->assertCount(2, Expense::all());
    }

    public function test_the_scope_survives_a_join(): void
    {
        $this->actingAs($this->adminA);

        $ids = Expense::join('stores', 'stores.id', '=', 'expenses.store_id')
            ->pluck('expenses.id');

        $this->assertTrue($ids->contains($this->expenseA->id));
        $this->assertFalse($ids->contains($this->expenseB->id));
    }
}
