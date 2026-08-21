<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\LedgerService;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;

class CustomerObserver
{
    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        AuditLog::create([
            'tenant_id' => $customer->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
            'action' => 'created',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Customer "updated" event.
     */
    public function updated(Customer $customer): void
    {
        $changes = collect($customer->getchanges())
                    ->except('updated_at')
                    ->mapWithKeys(fn ($newValue, $field) => [
                         $field => [$customer->getOriginal($field), $newValue]
                         ])
                    ->toArray();

        AuditLog::create([
            'tenant_id' => $customer->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

public function deleting(Customer $customer): void
{
    if ($customer->orders()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'customer' => __('messages.customer_has_orders'),
        ]);
    }

    if ($customer->payments()->exists()) {
        throw ValidationException::withMessages([
            'customer' => __('messages.customer_has_payments'),
        ]);
    }

    $balance = app(LedgerService::class)
        ->getBalance($customer->tenant_id, $customer->id);

    if ($balance != 0) {
        throw ValidationException::withMessages([
            'customer' => __('messages.customer_has_balance'),
        ]);
    }
}
    /**
     * Handle the Customer "deleted" event.
     */
    public function deleted(Customer $customer): void
    {
        AuditLog::create([
            'tenant_id' => $customer->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
            'action' => 'deleted',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Customer "restored" event.
     */
    public function restored(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "force deleted" event.
     */
    public function forceDeleted(Customer $customer): void
    {
        //
    }
}
