<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
         AuditLog::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'action' => 'created',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
          $changes = collect($product->getChanges())
                    ->except('updated_at')
                    ->mapWithKeys(fn ($newValue, $field) => [
                         $field => [$product->getOriginal($field), $newValue]
                         ])
                    ->toArray();

        AuditLog::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

    /**
     * Handle the Product "deleted" event.
     */
public function deleting(Product $product): void
{
    if ($product->orderItems()->exists()) {
        throw ValidationException::withMessages([
            'product' => __('messages.product_in_orders'),
        ]);
    }

    if ($product->purchaseOrderItems()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'product' => __('messages.product_in_purchase_orders'),
        ]);
    }

    if ($product->inventories()->where('quantity', '>', 0)->exists()) {
        throw ValidationException::withMessages([
            'product' => __('messages.product_has_stock'),
        ]);
    }
}
    public function deleted(Product $product): void
    {
           AuditLog::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'action' => 'deleted',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Product "restored" event.
     */
    public function restored(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "force deleted" event.
     */
    public function forceDeleted(Product $product): void
    {
        //
    }
}
