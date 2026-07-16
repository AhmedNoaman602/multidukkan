<?php

namespace App\Services;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;


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

    $product = Product::find($productId);
    $warehouse = Warehouse::find($warehouseId);

    $productName = $product?->name ?? "Product ID {$productId}";
    $warehouseName = $warehouse?->name ?? "Warehouse ID {$warehouseId}";
    $available = $inventory?->quantity ?? 0;

    if (!$inventory || $inventory->quantity < $quantity) {
        throw ValidationException::withMessages([
            'message' => "لا يوجد مخزون كافي لـ {$productName} في {$warehouseName}. المتاح: {$available}"
        ]);
    }
}

   public function deductStock(int $productId, int $warehouseId, int $quantity, ?int $referenceId = null, ?string $referenceType = null, ?int $userId = null, ?string $batchId = null, ?string $type = null): void{
        $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->firstOrFail();

        $inventory->decrement('quantity' , $quantity);

        InventoryTransaction::create([
            'tenant_id'      => $inventory->tenant_id,
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'type'           => $type ?? InventoryTransaction::TYPE_SALE,
            'quantity'       => $quantity,
            'reference_id'   => $referenceId,
            'reference_type' => $referenceType,
            'user_id'        => $userId,
            'batch_id'       => $batchId,
        ]);
        }

   public function restoreStock(int $productId, int $warehouseId, int $quantity, ?int $referenceId = null, ?string $referenceType = null, ?int $userId = null, ?string $batchId = null, ?string $type = null): void{
    $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->firstOrFail();

    $inventory->increment('quantity' , $quantity);

    InventoryTransaction::create([
        'tenant_id'      => $inventory->tenant_id,
        'warehouse_id'   => $warehouseId,
        'product_id'     => $productId,
        'type'           => $type ?? InventoryTransaction::TYPE_RETURN,
        'quantity'       => $quantity,
        'reference_id'   => $referenceId,
        'reference_type' => $referenceType,
        'user_id'        => $userId,
        'batch_id'       => $batchId,
    ]);
   }
   public function adjustStock(int $productId, int $warehouseId, int $quantity, string $direction , string $unitType = 'base', ?int $userId = null, ?string $notes = null): void{
   DB::transaction(function() use ($productId, $warehouseId, $quantity, $direction, $unitType, $userId, $notes){

     
   $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($unitType === 'secondary') {
            $product = Product::find($productId);
            if ($product?->conversion_factor) {
                $quantity = $quantity * $product->conversion_factor;
            }
        }

    if ($direction === 'out') {
        if ($inventory->quantity < $quantity) {
            throw ValidationException::withMessages([
                'message' => "لا يمكن إزالة {$quantity}. المتاح فقط: {$inventory->quantity}"
            ]);
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
            'user_id'        => $userId,
            'notes'          => $notes,
        ]);
   }
   ); 
   }
}
