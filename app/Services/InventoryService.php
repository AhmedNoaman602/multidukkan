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
   public function adjustStock(int $productId, int $warehouseId, int $quantity, string $direction , string $unitType = 'base', ?int $userId = null, ?string $notes = null, ?string $batchId = null): void{
   DB::transaction(function() use ($productId, $warehouseId, $quantity, $direction, $unitType, $userId, $notes, $batchId){


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
            'batch_id'       => $batchId,
        ]);
   }
   );
   }

    /**
     * Set a warehouse's stock to an absolute quantity, going through adjustStock so the
     * delta is logged as an inventory_transactions row. Used by the product stocks[] editor,
     * the one remaining path that sets stock by absolute value rather than by delta.
     */
    public function setStock(int $productId, int $warehouseId, int $tenantId, ?int $quantity, ?int $threshold = null, ?int $userId = null, ?string $notes = null, ?string $batchId = null): Inventory
    {
        return DB::transaction(function () use ($productId, $warehouseId, $tenantId, $quantity, $threshold, $userId, $notes, $batchId) {
            $inventory = Inventory::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();

            if (!$inventory) {
                $inventory = Inventory::create([
                    'tenant_id'    => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id'   => $productId,
                    'quantity'     => 0,
                    'threshold'    => $threshold ?? 10,
                ]);
            } elseif ($threshold !== null) {
                $inventory->update(['threshold' => $threshold]);
            }

            if ($quantity !== null) {
                $delta = $quantity - $inventory->quantity;

                if ($delta > 0) {
                    $this->adjustStock($productId, $warehouseId, $delta, 'in', 'base', $userId, $notes, $batchId);
                } elseif ($delta < 0) {
                    $this->adjustStock($productId, $warehouseId, abs($delta), 'out', 'base', $userId, $notes, $batchId);
                }
            }

            return $inventory->fresh();
        });
    }
}
