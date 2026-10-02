<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * A manual transfer between two locations of the same store. Completed immediately:
     * the MVP has no request/approval step, so only users allowed to approve can create one.
     */
    public function transfer(array $data, User $user): StockTransfer
    {
        return DB::transaction(function () use ($data, $user) {
            $from = Warehouse::where('tenant_id', $user->tenant_id)->findOrFail($data['from_warehouse_id']);
            $to   = Warehouse::where('tenant_id', $user->tenant_id)->findOrFail($data['to_warehouse_id']);

            if ($from->id === $to->id || $from->store_id !== $to->store_id) {
                throw ValidationException::withMessages([
                    'to_warehouse_id' => __('messages.warehouse_not_in_store'),
                ]);
            }

            $products = Product::where('tenant_id', $user->tenant_id)
                ->whereIn('id', collect($data['items'])->pluck('product_id'))
                ->get()
                ->keyBy('id');

            $transfer = StockTransfer::create([
                'tenant_id'         => $user->tenant_id,
                'store_id'          => $from->store_id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id'   => $to->id,
                'type'              => StockTransfer::TYPE_MANUAL,
                'status'            => StockTransfer::STATUS_COMPLETED,
                'created_by'        => $user->id,
                'notes'             => $data['notes'] ?? null,
                'batch_id'          => (string) Str::uuid(),
                'completed_at'      => now(),
            ]);

            $items = [];
            foreach ($data['items'] as $itemData) {
                $product  = $products->get($itemData['product_id']) ?? throw ValidationException::withMessages([
                    'items' => __('messages.field_not_tenant_owned', ['attribute' => 'product_id']),
                ]);
                $unitType = $itemData['unit_type'] ?? 'base';

                $items[] = $transfer->items()->create([
                    'tenant_id'         => $user->tenant_id,
                    'product_id'        => $product->id,
                    'quantity'          => $itemData['quantity'],
                    'unit_type'         => $unitType,
                    'conversion_factor' => $product->factorFor($unitType),
                    'unit_name'         => $product->unitNameFor($unitType),
                ]);
            }

            // Move stock in product order, so two multi-line transfers lock rows in the same sequence.
            foreach (collect($items)->sortBy('product_id') as $item) {
                $this->inventory->transferStock(
                    $item->product_id,
                    $from->id,
                    $to->id,
                    $item->baseQuantity(),
                    $transfer,
                    $user->id
                );
            }

            return $transfer->load('items.product', 'fromWarehouse', 'toWarehouse', 'creator', 'order');
        });
    }

    /**
     * Refill the shelf from one storage location for a sale. System-generated: authorised by
     * the sale itself, so no approval and no role check — store_staff sales use it too.
     * Only StockFulfillmentService calls this; there is no route.
     *
     * @param array<int, int> $baseQuantities product_id => base units to move
     */
    public function replenish(Order $order, Warehouse $from, Warehouse $shelf, array $baseQuantities, User $user): StockTransfer
    {
        return DB::transaction(function () use ($order, $from, $shelf, $baseQuantities, $user) {
            $products = Product::where('tenant_id', $order->tenant_id)
                ->whereIn('id', array_keys($baseQuantities))
                ->get()
                ->keyBy('id');

            $transfer = StockTransfer::create([
                'tenant_id'         => $order->tenant_id,
                'store_id'          => $order->store_id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id'   => $shelf->id,
                'type'              => StockTransfer::TYPE_REPLENISHMENT,
                'status'            => StockTransfer::STATUS_COMPLETED,
                'order_id'          => $order->id,
                'created_by'        => $user->id,
                'batch_id'          => (string) Str::uuid(),
                'completed_at'      => now(),
            ]);

            ksort($baseQuantities);

            foreach ($baseQuantities as $productId => $quantity) {
                $transfer->items()->create([
                    'tenant_id'         => $order->tenant_id,
                    'product_id'        => $productId,
                    'quantity'          => $quantity,
                    'unit_type'         => 'base',
                    'conversion_factor' => 1,
                    'unit_name'         => $products->get($productId)?->unit ?? '',
                ]);

                $this->inventory->transferStock($productId, $from->id, $shelf->id, $quantity, $transfer, $user->id);
            }

            return $transfer;
        });
    }
}
