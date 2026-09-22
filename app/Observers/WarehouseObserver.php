<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Validation\ValidationException;

class WarehouseObserver
{
    public function created(Warehouse $warehouse): void
    {
        AuditLog::create([
            'tenant_id' => $warehouse->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Warehouse::class,
            'auditable_id' => $warehouse->id,
            'action' => 'created',
            'changes' => null,
        ]);
    }

    public function updated(Warehouse $warehouse): void
    {
        $changes = collect($warehouse->getChanges())
            ->except('updated_at')
            ->mapWithKeys(fn ($newValue, $field) => [
                $field => [$warehouse->getOriginal($field), $newValue]
            ])
            ->toArray();

        AuditLog::create([
            'tenant_id' => $warehouse->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Warehouse::class,
            'auditable_id' => $warehouse->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

    public function deleting(Warehouse $warehouse): void
    {
        if ($warehouse->inventoryTransactions()->exists()) {
            throw ValidationException::withMessages([
                'warehouse' => __('messages.warehouse_has_stock_history'),
            ]);
        }

        if ($warehouse->purchaseOrderItems()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'warehouse' => __('messages.warehouse_in_purchase_orders'),
            ]);
        }

        if ($warehouse->orderItems()->exists()) {
            throw ValidationException::withMessages([
                'warehouse' => __('messages.warehouse_in_orders'),
            ]);
        }

        if ($warehouse->inventories()->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'warehouse' => __('messages.warehouse_has_inventory'),
            ]);
        }

        app(InventoryService::class)->purgeEmptyStockRows('warehouse_id', $warehouse->id);
    }

    public function deleted(Warehouse $warehouse): void
    {
        AuditLog::create([
            'tenant_id' => $warehouse->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Warehouse::class,
            'auditable_id' => $warehouse->id,
            'action' => 'deleted',
            'changes' => null,
        ]);
    }
}
