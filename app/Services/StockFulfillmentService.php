<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Takes a sale's stock from the shelf, refilling it first from the same store's storage when
 * it's short (ADR-010). Every check happens before any write, under row locks, inside the
 * caller's transaction — so a store that can't cover the sale changes nothing.
 */
class StockFulfillmentService
{
    public function __construct(
        private InventoryService $inventory,
        private StockTransferService $transfers,
    ) {}

    /**
     * @param array<int, int> $baseNeeds product_id => base units the sale takes from $location
     */
    public function fulfill(Order $order, Warehouse $location, array $baseNeeds, User $user, string $batchId): void
    {
        ksort($baseNeeds);
        $productIds = array_keys($baseNeeds);

        // Storage only refills the shelf. A line recorded at a storage location (older data)
        // is served from that location alone.
        $sources = $location->isShelf()
            ? Warehouse::where('tenant_id', $order->tenant_id)
                ->where('store_id', $location->store_id)
                ->where('type', Warehouse::TYPE_STORAGE)
                ->get()
                ->keyBy('id')
            : collect();

        foreach ($productIds as $productId) {
            $this->inventory->ensureStockRow($productId, $location->id, $order->tenant_id, 0);
        }

        $rows = $this->inventory->lockRows($productIds, [$location->id, ...$sources->keys()]);

        $plan = [];
        foreach ($baseNeeds as $productId => $need) {
            $productRows = $rows->where('product_id', $productId);
            $onShelf = (int) ($productRows->firstWhere('warehouse_id', $location->id)?->quantity ?? 0);
            $shortfall = $need - $onShelf;

            if ($shortfall <= 0) {
                continue;
            }

            // Highest stock first; lowest warehouse id breaks ties.
            $candidates = $productRows
                ->filter(fn ($row) => $sources->has($row->warehouse_id) && $row->quantity > 0)
                ->sort(fn ($a, $b) => [(int) $b->quantity, $a->warehouse_id] <=> [(int) $a->quantity, $b->warehouse_id]);

            $inStorage = (int) $candidates->sum('quantity');

            if ($inStorage < $shortfall) {
                throw new HttpResponseException(response()->json(['message' => __('messages.insufficient_store_stock', [
                    'product' => Product::find($productId)?->name ?? "Product ID {$productId}",
                    'needed'  => $need,
                    'shelf'   => $onShelf,
                    'storage' => $inStorage,
                ])], 422));
            }

            foreach ($candidates as $row) {
                $take = min((int) $row->quantity, $shortfall);
                $plan[$row->warehouse_id][$productId] = $take;
                $shortfall -= $take;

                if ($shortfall === 0) {
                    break;
                }
            }
        }

        ksort($plan);
        foreach ($plan as $sourceId => $quantities) {
            $this->transfers->replenish($order, $sources[$sourceId], $location, $quantities, $user);
        }

        foreach ($baseNeeds as $productId => $need) {
            $this->inventory->deductStock($productId, $location->id, $need, $order->id, Order::class, $user->id, $batchId);
        }
    }
}
