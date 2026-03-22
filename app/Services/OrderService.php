<?php

namespace App\Services;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
/**
 * Service for managing order lifecycle and coordination with the ledger.
 * 
 * This service handles the creation of orders, including calculating totals
 * from items and automatically charging the customer's ledger.
 * 
 * Connections:
 * - Depends on {@see \App\Services\LedgerService} to record order charges.
 */
class OrderService
{
    /**
     * Create a new class instance.
     */
     public function __construct(protected LedgerService $ledger , protected InventoryService $inventory){}

   public function createOrder(array $data) : Order{
     return DB::transaction(function () use ($data) {
            $order = Order::create([
                'tenant_id'   => $data['tenant_id'],
                'store_id'    => $data['store_id'],
                'customer_id' => $data['customer_id'],
                'created_by'  => $data['created_by'] ?? null,
                'notes'       => $data['notes'] ?? null,
            ]);

            $totalAmount = 0;

            foreach ($data['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $warehouseId = $itemData['warehouse_id'] ?? null;

                if($warehouseId){
                    $this->inventory->checkStock($product->id, $warehouseId, $itemData['quantity']);
                }
                
                $orderItem = $order->items()->create([
                    'product_id'   => $product->id,
                    'product_name' => $product->name,
                    'quantity'     => $itemData['quantity'],
                    'unit_price'   => $product->price,
                    'warehouse_id' => $warehouseId,
                ]);

                if($warehouseId){
                    $this->inventory->deductStock(
                        $product->id,
                        $warehouseId,
                        $itemData['quantity'],
                        $order->id,
                        Order::class,
                    );
                }

                $totalAmount += ($orderItem->unit_price * $orderItem->quantity);
            }

            // Charge the ledger
            $this->ledger->chargeOrder([
                'tenant_id'   => $order->tenant_id,
                'customer_id' => $order->customer_id,
                'store_id'    => $order->store_id,
                'order_id'    => $order->id,
                'amount'      => $totalAmount,
            ]);

            return $order->load('items');
        });
   }

   public function cancelOrder(Order $order): void
{
    DB::transaction(function () use ($order) {
        $total = $order->items()
            ->sum(DB::raw('unit_price * quantity'));

        foreach ($order->items as $item) {
            if ($item->warehouse_id) {
                $this->inventory->restoreStock(
                    $item->warehouse_id,
                    $item->product_id,
                    $item->quantity,
                    $order->id,
                    Order::class,
                );
            }
        }

        $this->ledger->reverseOrder([
            'tenant_id'   => $order->tenant_id,
            'customer_id' => $order->customer_id,
            'store_id'    => $order->store_id,
            'order_id'    => $order->id,
            'amount'      => $total,
        ]);

        $order->delete();
    });
}
}


