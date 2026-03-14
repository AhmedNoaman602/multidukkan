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
     public function __construct(protected LedgerService $ledger){}

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
                
                $orderItem = $order->items()->create([
                    'product_id'   => $product->id,
                    'product_name' => $product->name,
                    'quantity'     => $itemData['quantity'],
                    'unit_price'   => $product->price,
                ]);

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
}
