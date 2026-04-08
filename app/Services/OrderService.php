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

   public function createOrder(array $data): Order
{
    return DB::transaction(function () use ($data) {
        $user = auth()->user();

        $order = Order::create([
            'tenant_id'   => $user->tenant_id,
            'store_id'    => $user->store_id ?? $data['store_id'],
            'customer_id' => $data['customer_id'],
            'created_by'  => $user->id,
            'notes'       => $data['notes'] ?? null,
        ]);

        $totalAmount = 0;
        $customer = \App\Models\Customer::findOrFail($data['customer_id']);

        foreach ($data['items'] as $itemData) {
            $product     = Product::findOrFail($itemData['product_id']);
            $warehouseId = $itemData['warehouse_id'] ?? null;
            $unitType = $itemData['unit_type'] ?? 'base';

            $stockQuantity = $unitType === 'secondary' && $product->conversion_factor
        ? $itemData['quantity'] * $product->conversion_factor
        : $itemData['quantity'];

            if ($warehouseId) {
                $this->inventory->checkStock($product->id, $warehouseId, $stockQuantity);
            }
            $price = match($customer->price_tier) {
            'a'     => $product->price_a ?? $product->price,
            'b'     => $product->price_b ?? $product->price,
            'c'     => $product->price_c ?? $product->price,
            'd'     => $product->price_d ?? $product->price,
            'e'     => $product->price_e ?? $product->price,
            default => $product->price,
            };
            $unitPrice = $unitType === 'secondary' && $product->conversion_factor
        ? $price * $product->conversion_factor
        : $price;

            $orderItem = $order->items()->create([
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'quantity'     => $itemData['quantity'],
                'unit_type' => $unitType,
                'unit_price'   => $unitPrice,
                'warehouse_id' => $warehouseId,
            ]);

            if ($warehouseId) { // return to it
                $this->inventory->deductStock($product->id, $warehouseId, $stockQuantity, $order->id, Order::class);
            }

            $totalAmount += ($orderItem->unit_price * $orderItem->quantity);
        }

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
        $stockQuantity = $item->unit_type === 'secondary' && $item->product->conversion_factor
            ? $item->quantity * $item->product->conversion_factor
            : $item->quantity;

        $this->inventory->restoreStock($item->product_id, $item->warehouse_id, $stockQuantity, $order->id, Order::class);
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


