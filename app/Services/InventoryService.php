<?php

namespace App\Services;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use InvalidArgumentException;

class InventoryService
{
    /**
     * Create a new class instance.
     */

  public function checkStock(int $productId, int $warehouseId, int $quantity): void
{
    $inventory = Inventory::where('warehouse_id', $warehouseId)
        ->where('product_id', $productId)
        ->first();

    $product = \App\Models\Product::find($productId);
    $warehouse = \App\Models\Warehouse::find($warehouseId);

    $productName = $product?->name ?? "Product ID {$productId}";
    $warehouseName = $warehouse?->name ?? "Warehouse ID {$warehouseId}";
    $available = $inventory?->quantity ?? 0;

    if (!$inventory || $inventory->quantity < $quantity) {
        throw new InvalidArgumentException(
            "لا يوجد مخزون كافي لـ {$productName} في {$warehouseName}. المتاح: {$available}"
        );
    }
}

   public function deductStock(int $productId, int $warehouseId, int $quantity, ?int $referenceId = null, ?string $referenceType = null): void{
        $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->firstOrFail();   
        
        $inventory->decrement('quantity' , $quantity);

        InventoryTransaction::create([
            'tenant_id'      => $inventory->tenant_id,
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'type'           => InventoryTransaction::TYPE_SALE,
            'quantity'       => $quantity,
            'reference_id'   => $referenceId,
            'reference_type' => $referenceType,
        ]);
        }

   public function restoreStock(int $productId, int $warehouseId, int $quantity, ?int $referenceId = null, ?string $referenceType = null): void{
    $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->firstOrFail();

    $inventory->increment('quantity' , $quantity);

    InventoryTransaction::create([
        'tenant_id'      => $inventory->tenant_id,
        'warehouse_id'   => $warehouseId,
        'product_id'     => $productId,
        'type'           => InventoryTransaction::TYPE_RETURN,
        'quantity'       => $quantity,
        'reference_id'   => $referenceId,
        'reference_type' => $referenceType,
    ]);
   }
   public function adjustStock(int $productId, int $warehouseId, int $quantity, string $direction): void{
        $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->firstOrFail();

    if ($direction === 'out') {
        if ($inventory->quantity < $quantity) {
            throw new InvalidArgumentException(
                "لا يمكن إزالة {$quantity}. المتاح فقط: {$inventory->quantity}"
            );
        }
        $inventory->decrement('quantity', $quantity);
        $type = InventoryTransaction::TYPE_ADJUSTMENT_OUT;
    } else {
        $inventory->increment('quantity', $quantity);
        $type = InventoryTransaction::TYPE_ADJUSTMENT_IN;
    }
             InventoryTransaction::create([
            'tenant_id'      => $inventory->tenant_id,
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'type'           => $type,
            'quantity'       => $quantity,
        ]);
   }
}
