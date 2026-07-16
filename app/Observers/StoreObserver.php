<?php

namespace App\Observers;

use App\Models\Store;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;

class StoreObserver
{
    /**
     * Handle the Store "created" event.
     */
    public function created(Store $store): void
    {
        AuditLog::create([
            'tenant_id' => $store->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Store::class,
            'auditable_id' => $store->id,
            'action' => 'created',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Store "updated" event.
     */
    public function updated(Store $store): void
    {
        $changes = collect($store->getChanges())
            ->except('updated_at')
            ->mapWithKeys(fn ($newValue, $field) => [
                $field => [$store->getOriginal($field), $newValue]
            ])
            ->toArray();

        AuditLog::create([
            'tenant_id' => $store->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Store::class,
            'auditable_id' => $store->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

    public function deleting(Store $store): void
{
    $storeCount = Store::where('tenant_id', $store->tenant_id)->count();
    if ($storeCount <= 1) {
        throw ValidationException::withMessages([
            'store' => 'Cannot delete the only store. At least one store is required.',
        ]);
    }

    if ($store->warehouses()->exists()) {
        throw ValidationException::withMessages([
            'store' => 'Cannot delete a store with existing warehouses. Remove warehouses first.',
        ]);
    }

    if ($store->orders()->whereUnpaid()->exists()) {
        throw ValidationException::withMessages([
            'store' => 'Cannot delete a store with unpaid orders. Settle all orders first.',
        ]);
    }
}

    /**
     * Handle the Store "deleted" event.
     */
    public function deleted(Store $store): void
    {
        AuditLog::create([
            'tenant_id' => $store->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Store::class,
            'auditable_id' => $store->id,
            'action' => 'deleted',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Store "restored" event.
     */
    public function restored(Store $store): void
    {
        //
    }

    /**
     * Handle the Store "force deleted" event.
     */
    public function forceDeleted(Store $store): void
    {
        //
    }
}
