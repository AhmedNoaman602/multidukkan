<?php

namespace App\Services;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        throw new HttpResponseException(
            response()->json(['message' => __('messages.insufficient_stock', [
                'product'   => $productName,
                'warehouse' => $warehouseName,
                'available' => $available,
            ])], 422)
        );
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
    /**
     * Ensure a warehouse/product inventory row exists, without moving any stock.
     * Callers that legitimately introduce a new warehouse/product pairing (purchase
     * receiving, the product stocks[] editor) call this first; deductStock and
     * restoreStock stay strict and fail on a missing row.
     */
    public function ensureStockRow(int $productId, int $warehouseId, int $tenantId, ?int $threshold = null): Inventory
    {
        return Inventory::firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'product_id'   => $productId,
            ],
            [
                'tenant_id' => $tenantId,
                'quantity'  => 0,
                'threshold' => $threshold ?? 10,
            ]
        );
    }

    /**
     * Move base-unit stock between two locations: one TRANSFER_OUT and one TRANSFER_IN row,
     * both referencing the transfer and sharing its batch_id. Both inventory rows are locked
     * in id order, so concurrent transfers over the same pair queue instead of deadlocking.
     */
    public function transferStock(int $productId, int $fromWarehouseId, int $toWarehouseId, int $baseQuantity, StockTransfer $transfer, ?int $userId = null): void
    {
        DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $baseQuantity, $transfer, $userId) {
            $this->ensureStockRow($productId, $toWarehouseId, $transfer->tenant_id, 0);

            $ids = Inventory::where('product_id', $productId)
                ->whereIn('warehouse_id', [$fromWarehouseId, $toWarehouseId])
                ->pluck('id');

            $rows = Inventory::whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('warehouse_id');

            $source = $rows->get($fromWarehouseId);
            $destination = $rows->get($toWarehouseId);

            if (!$source || $source->quantity < $baseQuantity) {
                throw new HttpResponseException(
                    response()->json(['message' => __('messages.insufficient_stock', [
                        'product'   => Product::find($productId)?->name ?? "Product ID {$productId}",
                        'warehouse' => Warehouse::find($fromWarehouseId)?->name ?? "Warehouse ID {$fromWarehouseId}",
                        'available' => $source?->quantity ?? 0,
                    ])], 422)
                );
            }

            $source->decrement('quantity', $baseQuantity);
            $destination->increment('quantity', $baseQuantity);

            foreach ([
                [$fromWarehouseId, InventoryTransaction::TYPE_TRANSFER_OUT],
                [$toWarehouseId, InventoryTransaction::TYPE_TRANSFER_IN],
            ] as [$warehouseId, $type]) {
                InventoryTransaction::create([
                    'tenant_id'      => $transfer->tenant_id,
                    'warehouse_id'   => $warehouseId,
                    'product_id'     => $productId,
                    'type'           => $type,
                    'quantity'       => $baseQuantity,
                    'reference_id'   => $transfer->id,
                    'reference_type' => StockTransfer::class,
                    'user_id'        => $userId,
                    'batch_id'       => $transfer->batch_id,
                ]);
            }
        });
    }

    /**
     * Remove the zero-quantity stock rows left behind when a product or warehouse is deleted.
     * Replaces the old Product::booted() deleting hook, which ran unconditionally and would
     * have silently satisfied a RESTRICT on inventory.product_id by emptying the table first.
     * Callers must have already established that no stock history exists; the guard below is
     * a second line of defence, not the primary one.
     */
    public function purgeEmptyStockRows(string $column, int $id): int
    {
        if (!in_array($column, ['product_id', 'warehouse_id'], true)) {
            throw new \InvalidArgumentException("Unsupported inventory column [{$column}].");
        }

        $rows = Inventory::where($column, $id)->get();

        foreach ($rows as $row) {
            if ($row->quantity != 0) {
                throw new \RuntimeException(
                    "Refusing to purge inventory row {$row->id}: quantity is {$row->quantity}, not zero."
                );
            }
        }

        $hasHistory = InventoryTransaction::where($column, $id)->exists();

        if ($hasHistory) {
            throw new \RuntimeException(
                "Refusing to purge inventory rows for {$column}={$id}: stock history exists."
            );
        }

        return Inventory::where($column, $id)->delete();
    }

   public function adjustStock(int $productId, int $warehouseId, int $quantity, string $direction , string $unitType = 'base', ?int $userId = null, ?string $notes = null, ?string $batchId = null): void{
   DB::transaction(function() use ($productId, $warehouseId, $quantity, $direction, $unitType, $userId, $notes, $batchId){


   $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($unitType === 'secondary') {
            $quantity = $quantity * (Product::find($productId)?->factorFor($unitType) ?? 1);
        }

    if ($direction === 'out') {
        if ($inventory->quantity < $quantity) {
            throw new HttpResponseException(
                response()->json(['message' => __('messages.insufficient_removal_quantity', [
                    'quantity'  => $quantity,
                    'available' => $inventory->quantity,
                ])], 422)
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
            $inventory = $this->ensureStockRow($productId, $warehouseId, $tenantId, $threshold);

            if (!$inventory->wasRecentlyCreated && $threshold !== null) {
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
