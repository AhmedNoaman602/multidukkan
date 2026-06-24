<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "deleted" event.
     */
public function deleting(Product $product): void
{
    if ($product->orderItems()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'product' => 'Cannot delete a product that appears in existing orders.',
        ]);
    }

    if ($product->purchaseOrderItems()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'product' => 'Cannot delete a product that appears in existing purchase orders.',
        ]);
    }

    if ($product->inventories()->where('quantity', '>', 0)->exists()) {
        throw ValidationException::withMessages([
            'product' => 'Cannot delete a product with existing stock. Reduce stock to zero first.',
        ]);
    }
}
    public function deleted(Product $product): void
    {
        //
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
