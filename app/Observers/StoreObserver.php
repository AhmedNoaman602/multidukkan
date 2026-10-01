<?php

namespace App\Observers;

use App\Models\Store;
use App\Models\AuditLog;
use App\Models\Warehouse;
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
            'store' => __('messages.store_is_only_store'),
        ]);
    }

    if ($store->warehouses()->where('type', Warehouse::TYPE_STORAGE)->exists()) {
        throw ValidationException::withMessages([
            'store' => __('messages.store_has_warehouses'),
        ]);
    }

    // The shelf belongs to the store and goes with it (warehouses.store_id cascades),
    // but only while it has never held or moved stock.
    $shelf = $store->shelf;

    if ($shelf && (
        $shelf->inventories()->where('quantity', '>', 0)->exists()
        || $shelf->inventoryTransactions()->exists()
        || $shelf->orderItems()->exists()
        || $shelf->purchaseOrderItems()->withTrashed()->exists()
    )) {
        throw ValidationException::withMessages([
            'store' => __('messages.store_shelf_in_use'),
        ]);
    }

    if ($store->orders()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'store' => __('messages.store_has_orders'),
        ]);
    }

    if ($store->ledgerEntries()->exists()) {
        throw ValidationException::withMessages([
            'store' => __('messages.store_has_ledger_entries'),
        ]);
    }

    if ($store->users()->exists()) {
        throw ValidationException::withMessages([
            'store' => __('messages.store_has_users'),
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
