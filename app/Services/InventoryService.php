<?php

namespace App\Services;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;


class InventoryService
{
   public function deductStock(int $productId, int $warehouseId, int $quantity, ?int $referenceId = null, ?string $referenceType = null, ?int $userId = null, ?string $batchId = null, ?string $type = null): void{
        $inventory = Inventory::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();

        // A clear 422 instead of the SQL error an unsigned quantity would throw below zero.
        if ($inventory->quantity < $quantity) {
            $this->failInsufficientStock($productId, $warehouseId, $inventory->quantity);
        }

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

    private function failInsufficientStock(int $productId, int $warehouseId, int $available): never
    {
        throw new HttpResponseException(
            response()->json(['message' => __('messages.insufficient_stock', [
                'product'   => Product::find($productId)?->name ?? "Product ID {$productId}",
                'warehouse' => Warehouse::find($warehouseId)?->name ?? "Warehouse ID {$warehouseId}",
                'available' => $available,
            ])], 422)
        );
    }

    /**
     * Lock every inventory row for these products at these locations, in id order, so
     * every sale and transfer touching overlapping rows queues the same way.
     *
     * @return Collection<int, Inventory>
     */
    public function lockRows(array $productIds, array $warehouseIds): Collection
    {
        $ids = Inventory::whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->pluck('id');

        return Inventory::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
// ay haga
    /**
     * Shelf and storage stock per product for one store, in one grouped query. Products with
     * no stock rows come back as zeros. Display only — sales re-check under lock.
     */
    public function storeAvailability(int $storeId, array $productIds): array
    {
        $rows = DB::table('inventory')
            ->join('warehouses', 'warehouses.id', '=', 'inventory.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->whereIn('inventory.product_id', $productIds)
            ->groupBy('inventory.product_id')
            ->selectRaw("inventory.product_id,
                SUM(CASE WHEN warehouses.type = 'shelf' THEN inventory.quantity ELSE 0 END) as shelf_quantity,
                SUM(CASE WHEN warehouses.type = 'storage' THEN inventory.quantity ELSE 0 END) as storage_quantity")
            ->get()
            ->keyBy('product_id');

        return collect($productIds)->map(function ($productId) use ($rows) {
            $shelf = (int) ($rows[$productId]->shelf_quantity ?? 0);
            $storage = (int) ($rows[$productId]->storage_quantity ?? 0);

            return [
                'product_id'       => (int) $productId,
                'shelf_quantity'   => $shelf,
                'storage_quantity' => $storage,
                'total_quantity'   => $shelf + $storage,
            ];
        })->values()->all();
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
                $this->failInsufficientStock($productId, $fromWarehouseId, $source?->quantity ?? 0);
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
