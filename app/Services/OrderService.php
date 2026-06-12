<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Handles the full lifecycle of customer orders.
 *
 * Flow:
 * 1. Validate stock → 2. Create order + items → 3. Deduct stock
 * → 4. Charge ledger → 5. Apply credit if available → 6. Return order
 *
 * Called by: OrderController
 * Calls: InventoryService, LedgerService
 */
class OrderService
{
    /**
     * Create a new class instance.
     */
    public function __construct(protected LedgerService $ledger, protected InventoryService $inventory) {}

    private function generateInvoiceNumber(int $tenantId): string
    {
        $year = now()->year;

        $last = Order::where('tenant_id', $tenantId)
            ->where('invoice_number', 'like', "{$year}-%")
            ->orderByDesc('id')
            ->value('invoice_number');

        $lastNumber = $last ? (int) substr($last, -3) : 0;
        $next = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);

        return "{$year}-{$next}";
    }

    public function createOrder(array $data): Order
    {
        // We wrap the entire process in a database transaction to ensure that all database operations
        // (saving the order, deducting stock, updating ledger, applying credit) either succeed completely or roll back together.
        return DB::transaction(function () use ($data) {
            // Retrieve the currently authenticated user who is placing the order.
            $user = auth()->user();

            // Fetch the customer details from the database or fail with a 404 response if the customer doesn't exist.
            $customer = Customer::findOrFail($data['customer_id']);

            // --- STOCK CHECKING BLOCK ---
            // First, we aggregate order items by combining quantities for duplicate products in the same warehouse.
            // This ensures we check the total requested quantity for each product/warehouse combination at once.
            $aggregated = [];
            foreach ($data['items'] as $item) {
                $key = $item['product_id'].'_'.($item['warehouse_id'] ?? 'none');
                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = $item;
                } else {
                    $aggregated[$key]['quantity'] += $item['quantity'];
                }
            }

            // Next, we check if there is sufficient stock in the warehouse for each aggregated item.
            // If the unit type is 'secondary', we convert the quantity to the base unit using the product's conversion factor.
            foreach ($aggregated as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $warehouseId = $itemData['warehouse_id'] ?? null;
                $unitType = $itemData['unit_type'] ?? 'base';

                $stockQuantity = $unitType === 'secondary' && $product->conversion_factor
                    ? $itemData['quantity'] * $product->conversion_factor
                    : $itemData['quantity'];

                // If a warehouse is specified, call the inventory service to check if we have enough stock.
                if ($warehouseId) {
                    $this->inventory->checkStock($product->id, $warehouseId, $stockQuantity);
                }
            }
            // --- END OF STOCK CHECKING BLOCK ---

            // --- ITEM VALIDATION AND PRICE CALCULATION BLOCK ---
            // Loop through each item in the order to validate the product exists, check the stock quantity,
            // and calculate the correct price based on the customer's specific pricing tier (A, B, C, D, E, or default).
            $validatedItems = [];
            foreach ($data['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $warehouseId = $itemData['warehouse_id'] ?? null;
                $unitType = $itemData['unit_type'] ?? 'base';

                // Determine stock quantity to verify (converting from secondary unit to base unit if necessary).
                $stockQuantity = $unitType === 'secondary' && $product->conversion_factor
                    ? $itemData['quantity'] * $product->conversion_factor
                    : $itemData['quantity'];

                // Select the product price based on the customer's price tier configuration.
                $price = match ($customer->price_tier) {
                    'a' => $product->price_a ?? $product->price,
                    'b' => $product->price_b ?? $product->price,
                    'c' => $product->price_c ?? $product->price,
                    'd' => $product->price_d ?? $product->price,
                    'e' => $product->price_e ?? $product->price,
                    default => $product->price,
                };

                // Calculate the final unit price, adjusting it if using a secondary unit and conversion factor.
                $unitPrice = $unitType === 'secondary' && $product->conversion_factor
                    ? $price * $product->conversion_factor
                    : $price;

                // Temporarily store the validated data of the item to be processed after the order record is created.
                $validatedItems[] = [
                    'product' => $product,
                    'warehouseId' => $warehouseId,
                    'stockQty' => $stockQuantity,
                    'quantity' => $itemData['quantity'],
                    'unitType' => $unitType,
                    'unitPrice' => $unitPrice,
                ];
            }

            // --- ORDER CREATION BLOCK ---
            // Create the main Order record with tenant, store, customer, creator, and generated invoice number details.
            $order = Order::create([
                'tenant_id' => $user->tenant_id,
                'store_id' => $user->store_id ?? $data['store_id'],
                'customer_id' => $data['customer_id'],
                'created_by' => $user->id,
                'notes' => $data['notes'] ?? null,
                'discount' => $data['discount'] ?? 0,
                'customer_name_snapshot' => $customer->name,
                'invoice_number' => $this->generateInvoiceNumber($user->tenant_id),
            ]);

            // If a custom order date is provided in the input, override the default timestamps and save the custom date.
            if (isset($data['order_date'])) {
                $order->timestamps = false;
                $order->created_at = $data['order_date'];
                $order->save();
                $order->timestamps = true;
            }

            // --- ORDER ITEMS & STOCK DEDUCTION BLOCK ---
            // For each validated item, create the line item record in the database and deduct the physical stock from the inventory.
            // Also, compute the running total price of all items in the order.
            $totalAmount = 0;
            foreach ($validatedItems as $v) {
                $orderItem = $order->items()->create([
                    'product_id' => $v['product']->id,
                    'product_name' => $v['product']->name,
                    'quantity' => $v['quantity'],
                    'unit_type' => $v['unitType'],
                    'unit_price' => $v['unitPrice'],
                    'warehouse_id' => $v['warehouseId'],
                ]);

                // If a warehouse is assigned, deduct the stock from that warehouse for this order item.
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

            // --- LEDGER & CREDIT CHARGING BLOCK ---
            // Check the customer's current balance before processing this order.
            // A negative balance indicates the customer has an active credit line available.
            $balanceBefore = $this->ledger->getBalance($order->tenant_id, $order->customer_id);
            $creditAvailable = max(0, -$balanceBefore);

            // Calculate the discount and determine the final charge amount for the order (total amount minus the discount).
            $discount = max(0, min($data['discount'] ?? 0, $totalAmount));
            $chargeAmount = round($totalAmount - $discount, 2);

            // Update the order with its final total cost.
            $order->update([
                'total' => round($totalAmount - $discount, 2),
            ]);

            // Post a charge entry to the customer's ledger for this order.
            $this->ledger->chargeOrder([
                'tenant_id' => $order->tenant_id,
                'customer_id' => $order->customer_id,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'amount' => $chargeAmount,
                'invoice_number' => $order->invoice_number,
            ]);

            // If the customer has credit available, automatically apply it to settle or reduce the order balance.
            if ($creditAvailable > 0) {
                // Determine how much credit can be applied (up to the full charge amount of the order).
                $applyAmount = min($creditAvailable, $chargeAmount);

                // Create a payment record to document the transaction using the customer's credit.
                $payment = Payment::create([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'amount' => $applyAmount,
                    'method' => 'credit',
                    'paid_at' => now(),
                ]);

                // Register the payment application on the ledger to reduce the order's outstanding balance.
                $this->ledger->applyAmount([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'store_id' => $order->store_id,
                    'payment_id' => $payment->id,
                    'amount' => $applyAmount,
                    'invoice_number' => $order->invoice_number,
                ]);

                // Record the consumption of the customer's credit balance in the ledger.
                $this->ledger->consumeCredit([
                    'tenant_id' => $order->tenant_id,
                    'customer_id' => $order->customer_id,
                    'store_id' => $order->store_id,
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'amount' => $applyAmount,
                    'invoice_number' => $order->invoice_number,
                ]);
            }

            // Return the newly created order structure along with all associated items.
            return $order->load('items');
        });
    }

    public function adjustItem(Order $order, OrderItem $item, array $data): void
    {
        $oldQty = $item->quantity;
        $newQty = $data['quantity'] ?? $oldQty;
        $delta = $newQty - $oldQty;

        // Step 1 — stock delta
        if ($delta > 0) {
            $this->inventory->deductStock(
                $item->product_id, $item->warehouse_id,
                $delta, $order->id, Order::class
            );
        } elseif ($delta < 0) {
            $this->inventory->restoreStock(
                $item->product_id, $item->warehouse_id,
                abs($delta), $order->id, Order::class
            );
        }

        // Step 2 — update item
        $item->update([
            'quantity' => $newQty,
            'unit_price' => $data['unit_price'] ?? $item->unit_price,
            'total' => $newQty * ($data['unit_price'] ?? $item->unit_price),
        ]);

        // Step 3 — recalculate order total
        $newTotal = $order->items()->sum(DB::raw('unit_price * quantity'));
        $discount = (float) ($order->discount ?? 0);
        $newTotal = max(0, round($newTotal - $discount, 2));

        // Step 4 — update ledger + order
        $this->ledger->adjustOrderCharge($order, $newTotal);
    }

    public function addItem(Order $order, array $data)
    {
        $product = Product::findOrFail($data['product_id']);
        $warehouseId = $data['warehouse_id'];
        $unitType = $data['unit_type'] ?? 'base';
        $customer = $order->customer;

        $stockQuantity = $unitType === 'secondary' && $product->conversion_factor
        ? $data['quantity'] * $product->conversion_factor
        : $data['quantity'];
        
        $this->inventory->checkStock($product->id, $warehouseId, $stockQuantity);

       $price = match ($customer->price_tier) {
                    'a' => $product->price_a ?? $product->price,
                    'b' => $product->price_b ?? $product->price,
                    'c' => $product->price_c ?? $product->price,
                    'd' => $product->price_d ?? $product->price,
                    'e' => $product->price_e ?? $product->price,
                    default => $product->price,
                };

        $unitPrice = $data['unit_price'] ?? ($unitType === 'secondary' && $product->conversion_factor
    ? $price * $product->conversion_factor
    : $price);

      $this->inventory->deductStock($product->id,$warehouseId, $stockQuantity, $order->id,Order::class);

      $order->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'warehouse_id' => $warehouseId,
        'quantity' => $data['quantity'],
        'unit_price' => $unitPrice,
        'unit_type' => $unitType,
        'total' => $data['quantity'] * $unitPrice,
      ]);

      $newTotal = $order->items()->sum(DB::raw('unit_price * quantity'));
      $discount = (float) ($order->discount ?? 0);
      $newTotal = max(0, round($newTotal - $discount, 2));

      $this->ledger->adjustOrderCharge($order, $newTotal);
    }

    // OrderService@updateOrder
public function updateOrder(Order $order, array $data): Order
{
    $order->update($data);
    
    // If discount changed → recalculate total + update ledger
    if (isset($data['discount'])) {
        $newTotal = $order->items()->sum(DB::raw('unit_price * quantity'));
        $newTotal = max(0, round($newTotal - $order->discount, 2));
        $this->ledger->adjustOrderCharge($order, $newTotal);
    }
    
    return $order->load('items', 'payments', 'customer');
}
    /**
     * Cancel an order — restores stock and reverses ledger charge.
     *
     * Flow:
     * 1. Restore stock to warehouse for each item
     * 2. Create REVERSAL ledger entry
     * 3. Soft delete the order
     *
     * Called by: OrderController@destroy
     */
    public function cancelOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $chargeAmount = $order->total;

            foreach ($order->items as $item) {
                if ($item->warehouse_id) {
                    $stockQuantity = $item->unit_type === 'secondary' && $item->product->conversion_factor
                        ? $item->quantity * $item->product->conversion_factor
                        : $item->quantity;

                    $this->inventory->restoreStock($item->product_id, $item->warehouse_id, $stockQuantity, $order->id, Order::class);
                }
            }
            $this->ledger->reverseOrder([
                'tenant_id' => $order->tenant_id,
                'customer_id' => $order->customer_id,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'amount' => $chargeAmount,
                'invoice_number' => $order->invoice_number,
            ]);

            $order->delete();
        });
    }
}
