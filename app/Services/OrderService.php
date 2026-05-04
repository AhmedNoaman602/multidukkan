<?php

namespace App\Services;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Payment;
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
        $customer = Customer::findOrFail($data['customer_id']);
        // Add this before the validation loop in createOrder
$aggregated = [];
foreach ($data['items'] as $item) {
    $key = $item['product_id'] . '_' . ($item['warehouse_id'] ?? 'none');
    if (!isset($aggregated[$key])) {
        $aggregated[$key] = $item;
    } else {
        $aggregated[$key]['quantity'] += $item['quantity'];
    }
}

// Check stock against aggregated quantities
foreach ($aggregated as $itemData) {
    $product     = Product::findOrFail($itemData['product_id']);
    $warehouseId = $itemData['warehouse_id'] ?? null;
    $unitType    = $itemData['unit_type'] ?? 'base';

    $stockQuantity = $unitType === 'secondary' && $product->conversion_factor
        ? $itemData['quantity'] * $product->conversion_factor
        : $itemData['quantity'];

    if ($warehouseId) {
        $this->inventory->checkStock($product->id, $warehouseId, $stockQuantity);
    }
}

        $validatedItems = [];
        foreach ($data['items'] as $itemData) {
            $product     = Product::findOrFail($itemData['product_id']);
            $warehouseId = $itemData['warehouse_id'] ?? null;
            $unitType    = $itemData['unit_type'] ?? 'base';

            $stockQuantity = $unitType === 'secondary' && $product->conversion_factor
                ? $itemData['quantity'] * $product->conversion_factor
                : $itemData['quantity'];

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

            // Store validated item data for later
            $validatedItems[] = [
                'product'      => $product,
                'warehouseId'  => $warehouseId,
                'stockQty'     => $stockQuantity,
                'quantity'     => $itemData['quantity'],
                'unitType'     => $unitType,
                'unitPrice'    => $unitPrice,
            ];
        }

        $order = Order::create([
            'tenant_id'   => $user->tenant_id,
            'store_id'    => $user->store_id ?? $data['store_id'],
            'customer_id' => $data['customer_id'],
            'created_by'  => $user->id,
            'notes'       => $data['notes'] ?? null,
            'discount' => $data['discount'] ?? 0,
        ]);

        $totalAmount = 0;
        foreach ($validatedItems as $v) {
            $orderItem = $order->items()->create([
                'product_id'   => $v['product']->id,
                'product_name' => $v['product']->name,
                'quantity'     => $v['quantity'],
                'unit_type'    => $v['unitType'],
                'unit_price'   => $v['unitPrice'],
                'warehouse_id' => $v['warehouseId'],
            ]);

            if ($v['warehouseId']) {
                $this->inventory->deductStock(
                    $v['product']->id,
                    $v['warehouseId'],
                    $v['stockQty'],
                    $order->id,
                    Order::class
                );
            }

            $totalAmount += ($orderItem->unit_price * $orderItem->quantity);
        }
        $balanceBefore = $this->ledger->getBalance($order->tenant_id , $order->customer_id);
        $creditAvailable = max(0 , -$balanceBefore);
        // ✅ Step 4 — charge ledger
        $discount = max(0, min($data['discount'] ?? 0, $totalAmount));
        $chargeAmount = round($totalAmount - $discount, 2);
        
        $this->ledger->chargeOrder([
            'tenant_id'   => $order->tenant_id,
            'customer_id' => $order->customer_id,
            'store_id'    => $order->store_id,
            'order_id'    => $order->id,
            'amount'      => $chargeAmount,
        ]);

        if($creditAvailable > 0){
            $applyAmount = min($creditAvailable , $chargeAmount);

            $payment = Payment::create([
        'tenant_id'   => $order->tenant_id,
        'order_id'    => $order->id,
        'customer_id' => $order->customer_id,
        'amount'      => $applyAmount,
        'method'      => 'credit',
        'paid_at'     => now(),
    ]);
    $this->ledger->applyAmount([
        'tenant_id'   => $order->tenant_id,
        'order_id'    => $order->id,
        'customer_id' => $order->customer_id,
        'store_id'    => $order->store_id,
        'payment_id'  => $payment->id,
        'amount'      => $applyAmount,
    ]);
    $this->ledger->consumeCredit([
        'tenant_id'   => $order->tenant_id,
        'customer_id' => $order->customer_id,
        'store_id'    => $order->store_id,
        'order_id'    => $order->id,
        'payment_id'  => $payment->id,
        'amount'      => $applyAmount,
    ]);
        }

        return $order->load('items');
    });
}
   public function cancelOrder(Order $order): void
{
    DB::transaction(function () use ($order) {
        $subtotal = $order->items()
            ->sum(DB::raw('unit_price * quantity'));
        
        $discount = (float) ($order->discount ?? 0);
        $chargeAmount = max(0, round($subtotal - $discount, 2));

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
            'amount'      => $chargeAmount,
        ]);

        $order->delete();
    });
}
}


