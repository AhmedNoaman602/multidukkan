<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * CREDIT_PAYOUT: customer credit paid out in cash. It lowers the credit (a customer debit),
 * can never exceed the credit available after the customer lock, writes no payment row,
 * and is refused for walk-in customers.
 */
class CreditPayoutTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected Customer $customer;
    protected LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->ledger   = app(LedgerService::class);
    }

    private function giveCredit(Customer $customer, float $amount): void
    {
        $this->actingAs($this->admin)->postJson('/api/payments/auto', [
            'customer_id' => $customer->id,
            'amount'      => $amount,
            'method'      => 'cash',
        ])->assertStatus(201);
    }

    private function payOut(Customer $customer, float $amount): LedgerEntry
    {
        $this->actingAs($this->admin);

        return $this->ledger->payOutCredit([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $customer->id,
            'store_id'       => $this->store->id,
            'user_id'        => $this->admin->id,
            'amount'         => $amount,
            'reference_type' => 'manual',
            'reference_id'   => $customer->id,
        ]);
    }

    private function ledgerRows(): array
    {
        return DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_a_payout_within_the_available_credit_lowers_the_credit(): void
    {
        $this->giveCredit($this->customer, 100);
        $paymentsBefore = DB::table('payments')->count();

        $entry = $this->payOut($this->customer, 40);

        $this->assertSame('CREDIT_PAYOUT', $entry->type);
        $this->assertEquals(40, $entry->amount);
        $this->assertEquals(-60, $this->ledger->getBalance($this->tenant->id, $this->customer->id));
        $this->assertEquals(60, $this->ledger->getCreditBalance($this->tenant->id, $this->customer->id));
        $this->assertSame($paymentsBefore, DB::table('payments')->count(), 'A payout is not a payment.');
    }

    public function test_the_whole_available_credit_can_be_paid_out(): void
    {
        $this->giveCredit($this->customer, 100);

        $this->payOut($this->customer, 100);

        $this->assertEquals(0, $this->ledger->getBalance($this->tenant->id, $this->customer->id));
        $this->assertEquals(0, $this->ledger->getCreditBalance($this->tenant->id, $this->customer->id));
    }

    public function test_a_payout_above_the_available_credit_is_refused_and_writes_nothing(): void
    {
        $this->giveCredit($this->customer, 100);
        $before = $this->ledgerRows();

        try {
            $this->payOut($this->customer, 100.01);
            $this->fail('A payout above the available credit must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertEquals($before, $this->ledgerRows());
        $this->assertEquals(-100, $this->ledger->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_a_customer_without_credit_has_nothing_to_pay_out(): void
    {
        $before = $this->ledgerRows();

        $this->expectException(ValidationException::class);

        try {
            $this->payOut($this->customer, 10);
        } finally {
            $this->assertEquals($before, $this->ledgerRows());
        }
    }

    public function test_a_walk_in_customer_can_never_receive_a_payout(): void
    {
        $walkIn = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'is_walk_in' => true]);
        LedgerEntry::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $walkIn->id,
            'store_id'       => $this->store->id,
            'type'           => 'CREDIT_APPLY',
            'amount'         => 50,
            'reference_type' => 'manual',
            'reference_id'   => $walkIn->id,
        ]);
        $before = $this->ledgerRows();

        try {
            $this->payOut($walkIn, 10);
            $this->fail('A walk-in payout must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }

        $this->assertEquals($before, $this->ledgerRows());
    }

    public function test_single_and_batch_balances_agree_after_a_payout(): void
    {
        $other = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->giveCredit($this->customer, 100);
        $this->giveCredit($other, 30);

        $this->payOut($this->customer, 25);
        $this->payOut($other, 30);

        $batch = $this->ledger->getBalancesForCustomers($this->tenant->id, [$this->customer->id, $other->id]);

        $this->assertEquals($this->ledger->getBalance($this->tenant->id, $this->customer->id), $batch[$this->customer->id]);
        $this->assertEquals($this->ledger->getBalance($this->tenant->id, $other->id), $batch[$other->id]);
        $this->assertEquals(-75, $batch[$this->customer->id]);
        $this->assertEquals(0, $batch[$other->id]);
    }

    public function test_the_ledger_enum_accepts_credit_payout(): void
    {
        $this->giveCredit($this->customer, 10);
        $this->payOut($this->customer, 10);

        $this->assertDatabaseHas('ledger_entries', [
            'customer_id' => $this->customer->id,
            'type'        => 'CREDIT_PAYOUT',
            'amount'      => 10,
        ]);
    }
}
