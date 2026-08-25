<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Support\LocalDateRange;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        // Invoice numbering is business-calendar logic: the year on the invoice is the
        // shop's year. Resolved in UTC, a sale rung up at 02:30 Cairo on 1 January
        // would be numbered against the year that had already ended.
        //
        // The lookup below matches on the invoice_number prefix rather than a date
        // column, so it follows this year automatically — no range needed here.
        $year = now(LocalDateRange::businessTimezone())->year;

        $last = Order::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('invoice_number', 'like', "{$year}-%")
            ->orderByDesc('id')
            ->value('invoice_number');

        $lastNumber = $last ? (int) substr($last, strrpos($last, '-') + 1) : 0;
        $next = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);

        return "{$year}-{$next}";
    }

    private const MAX_INVOICE_RETRIES = 3;

    public function createOrder(array $data): Order
    {
        // Unique index on (tenant_id, invoice_number) guards against a collision;
        // retry with a freshly generated number if one ever happens.
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_INVOICE_RETRIES; $attempt++) {
            try {
                return $this->createOrderAttempt($data);
            } catch (QueryException $e) {
                if (!$this->isDuplicateInvoiceNumber($e)) {
                    throw $e;
                }

                $lastException = $e;
            }
        }

        throw $lastException;
    }

    private function isDuplicateInvoiceNumber(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'orders_tenant_id_invoice_number_unique');
    }

    private function createOrderAttempt(array $data): Order
    {
        // We wrap the entire process in a database transaction to ensure that all database operations
        // (saving the order, deducting stock, updating ledger, applying credit) either succeed completely or roll back together.
        return DB::transaction(function () use ($data) {
            // Retrieve the currently authenticated user who is placing the order.
            $user = auth()->user();

            // Groups every inventory_transaction row this order creation produces so the
            // activity feed can show one "sale" event instead of one row per line item.
            $batchId = (string) Str::uuid();

            // Fetch the customer details from the database or fail with a 404 response if the customer doesn't exist.
            $customer = Customer::findOrFail($data['customer_id']);
            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');


            $aggregated = [];
foreach ($data['items'] as $item) {
    $key = $item['product_id'].'_'.($item['warehouse_id'] ?? 'none');
    $product = $products[$item['product_id']];
    $unitType = $item['unit_type'] ?? 'base';

    $stockQty = $unitType === 'secondary' && $product->conversion_factor
        ? $item['quantity'] * $product->conversion_factor
        : $item['quantity'];

    if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
            'product_id'   => $item['product_id'],
            'warehouse_id' => $item['warehouse_id'] ?? null,
            'stockQty'     => $stockQty,
        ];
    } else {
        $aggregated[$key]['stockQty'] += $stockQty;
    }
}

foreach ($aggregated as $itemData) {
    if ($itemData['warehouse_id']) {
        $this->inventory->checkStock(
            $itemData['product_id'],
            $itemData['warehouse_id'],
            $itemData['stockQty']
        );
    }
}
            // --- END OF STOCK CHECKING BLOCK ---

            // --- ITEM VALIDATION AND PRICE CALCULATION BLOCK ---
            // Loop through each item in the order to validate the product exists, check the stock quantity,
            // and calculate the correct price based on the customer's specific pricing tier (A, B, C, D, E, or default).
            $validatedItems = [];
            foreach ($data['items'] as $itemData) {
                $product = $products[$itemData['product_id']];
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
                $unitPrice = isset($itemData['unit_price']) && $itemData['unit_price'] !== null
                ? (float) $itemData['unit_price']
                : ($unitType === 'secondary' && $product->conversion_factor
                    ? $price * $product->conversion_factor
                    : $price);

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
                // The business date the shop puts on the order — a calendar date, and the
                // only field the user controls here. It is deliberately NOT written to
                // created_at: that stays the true creation instant, so the order and the
                // ledger entry written alongside it agree about when this happened.
                'order_date' => $data['order_date'],
            ]);

            $mergedItems = [];
foreach ($validatedItems as $v) {
    // Key now includes unit_type — only merges truly identical rows
    $key = $v['product']->id . '_' . $v['warehouseId'] . '_' . $v['unitType'];
    
    if (isset($mergedItems[$key])) {
        $mergedItems[$key]['quantity'] += $v['quantity'];
        $mergedItems[$key]['stockQty'] += $v['stockQty'];
        // unitPrice stays — same product + same unit type = same price
    } else {
        $mergedItems[$key] = $v;
    }
}

            // --- ORDER ITEMS & STOCK DEDUCTION BLOCK ---
            // For each validated item, create the line item record in the database and deduct the physical stock from the inventory.
            // Also, compute the running total price of all items in the order.
            $totalAmount = 0;
            foreach ($mergedItems as $v) {
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
                        Order::class,
                        $user->id,
                        $batchId
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
            $calculatedTotal = round($totalAmount - $discount, 2);

            $chargeAmount = isset($data['manual_total']) && $data['manual_total'] !== null
    ? round((float) $data['manual_total'], 2)
    : $calculatedTotal;

            // Update the order with its final total cost.
            $order->update([
                'total' => $chargeAmount,
            ]);

            // Post a charge entry to the customer's ledger for this order.
            $this->ledger->chargeOrder([
                'tenant_id' => $order->tenant_id,
                'customer_id' => $order->customer_id,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'amount' => $chargeAmount,
                'invoice_number' => $order->invoice_number,
                'user_id' => $user->id,
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
                    'is_auto_reversible' => true,
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
                    'user_id' => $user->id,
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
                    'user_id' => $user->id,
                ]);
            }

            // --- PAY IMMEDIATELY BLOCK (QuickSale) ---
// If pay_immediately is set, create a cash payment inside the same transaction.
// This ensures order + payment are atomic — if payment fails, order rolls back too.
if (!empty($data['pay_immediately'])) {
 $cashAmount = round($chargeAmount - ($applyAmount ?? 0), 2);
 
    if ($cashAmount > 0) {
        
        $payment = Payment::create([
            'tenant_id'          => $order->tenant_id,
            'order_id'           => $order->id,
            'customer_id'        => $order->customer_id,
            'amount'             => $cashAmount,
            'method'             => $data['payment_method'] ?? 'cash',
            'is_auto_reversible' => false,
            'paid_at'            => now(),
        ]);

        $this->ledger->applyAmount([
            'tenant_id'      => $order->tenant_id,
            'order_id'       => $order->id,
            'customer_id'    => $order->customer_id,
            'store_id'       => $order->store_id,
            'payment_id'     => $payment->id,
            'amount'         => $cashAmount,
            'invoice_number' => $order->invoice_number,
            'user_id'        => $user->id,
        ]);
    }
}

            // Return the newly created order structure along with all associated items.
            return $order->load('items');
        });
    }

    public function adjustItem(Order $order, OrderItem $item, array $data): void
    {
        DB::transaction(function () use ($order, $item, $data) {
        $this->ensureOrderIsEditable($order);

        $oldQty = $item->quantity;
        $newQty = $data['quantity'] ?? $oldQty;
        $delta = $newQty - $oldQty;

        // Step 1 — stock delta
        if ($delta > 0) {
             $this->inventory->checkStock($item->product_id, $item->warehouse_id, $delta);
            $this->inventory->deductStock(
                $item->product_id, $item->warehouse_id,
                $delta, $order->id, Order::class, auth()->id()
            );
        } elseif ($delta < 0) {
            $this->inventory->restoreStock(
                $item->product_id, $item->warehouse_id,
                abs($delta), $order->id, Order::class, auth()->id()
            );
        }

        // Step 2 — update item
        $item->update([
            'quantity' => $newQty,
            'unit_price' => $data['unit_price'] ?? $item->unit_price,
        ]);

        // Step 3 — recalculate order total, update ledger + order
        $this->ledger->adjustOrderCharge($order, $this->recalculateTotal($order));
        });
    }

    /**
     * Tiered payment-lock rule for edits that change an order's total:
     * - No payments        → freely editable.
     * - Partially paid     → manager-or-above only (store_staff blocked).
     * - Fully paid/settled → locked for everyone; additional items need a new order/invoice.
     *
     * Audit trail for every edit that gets through is handled automatically by
     * OrderObserver::updated (fires on the Order::update() call inside adjustOrderCharge).
     */
    private function ensureOrderIsEditable(Order $order): void
    {
        if ($order->isSettled()) {
            throw ValidationException::withMessages([
                'order' => __('messages.order_fully_paid_locked'),
            ]);
        }

        $isPartiallyPaid = $order->settledAmount() > 0;
        $isManagerOrAbove = in_array(auth()->user()->role, ['tenant_admin', 'store_manager'], true);

        if ($isPartiallyPaid && !$isManagerOrAbove) {
            throw ValidationException::withMessages([
                'order' => __('messages.order_partial_payments_manager_only'),
            ]);
        }
    }

    /**
     * Recompute an order's total from its current items minus discount.
     * Overrides applied via manual_total at creation are not preserved past this point —
     * see docs/07-business-rules/financial-calculations.md.
     */
    private function recalculateTotal(Order $order): float
    {
        $subtotal = $order->items()->sum(DB::raw('unit_price * quantity'));
        $discount = (float) ($order->discount ?? 0);

        return max(0, round($subtotal - $discount, 2));
    }

    public function addItem(Order $order, array $data)
    {
        return DB::transaction(function () use ($order, $data) {
        $this->ensureOrderIsEditable($order);

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



     $existingItem = $order->items()
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouseId)
        ->where('unit_type', $unitType)
        ->first();

    if ($existingItem) {
        // Just bump the quantity on the existing row
        $existingItem->update([
            'quantity' => $existingItem->quantity + $data['quantity'],
        ]);
    } else {
        // Create a new row
        $order->items()->create([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'warehouse_id' => $warehouseId,
            'quantity'     => $data['quantity'],
            'unit_price'   => $unitPrice,
            'unit_type'    => $unitType,
        ]);
    }
          $this->inventory->deductStock($product->id, $warehouseId, $stockQuantity, $order->id, Order::class, auth()->id());

      $this->ledger->adjustOrderCharge($order, $this->recalculateTotal($order));
    });
}

    // OrderService@updateOrder
public function updateOrder(Order $order, array $data): Order
{
    // Discount directly changes the total — block it once the customer has paid against
    // the old number. Notes/order_date carry no money implications, so they're unaffected.
    if (isset($data['discount'])) {
        $this->ensureOrderIsEditable($order);
    }

    $order->update($data);

    // If discount changed → recalculate total + update ledger
    if (isset($data['discount'])) {
        $this->ledger->adjustOrderCharge($order, $this->recalculateTotal($order));
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
   public function cancelOrder(Order $order, \App\Models\User $user): void
{
    DB::transaction(function () use ($order, $user) {
        $chargeAmount = $order->total;

        // Block cancel if real money payments exist and aren't fully refunded
        $hasUnrefundedPayments = $order->payments()
            ->cashOnly()
            ->whereRaw('amount > COALESCE(refunded_amount, 0)')
            ->exists();

        if ($hasUnrefundedPayments) {
            throw ValidationException::withMessages([
                'order' => __('messages.refund_before_cancel')
            ]);
        }

        // Calculate credit portion
        $creditPayments = $order->payments()
    ->creditOnly()
    ->get();

    $creditPaymentsTotal = $creditPayments->sum('amount');

// Restore credit for each credit payment
foreach ($creditPayments as $payment) {
    $this->ledger->restoreCredit([
        'tenant_id'      => $order->tenant_id,
        'customer_id'    => $order->customer_id,
        'store_id'       => $order->store_id,
        'order_id'       => $order->id,
        'payment_id'     => $payment->id,
        'amount'         => $payment->amount,
        'invoice_number' => $order->invoice_number,
        'user_id'        => $user->id,
    ]);
}

        // Restore stock
        $batchId = (string) Str::uuid();
        foreach ($order->items as $item) {
            if ($item->warehouse_id) {
                $stockQuantity = $item->unit_type === 'secondary' && $item->product->conversion_factor
                    ? $item->quantity * $item->product->conversion_factor
                    : $item->quantity;

                $this->inventory->restoreStock(
                    $item->product_id,
                    $item->warehouse_id,
                    $stockQuantity,
                    $order->id,
                    Order::class,
                    $user->id,
                    $batchId
                );
            }
        }

      // Only REVERSAL the cash portion
$reversalAmount = $chargeAmount - $creditPaymentsTotal;
if ($reversalAmount > 0) {
    $this->ledger->reverseOrder([
        'tenant_id'      => $order->tenant_id,
        'customer_id'    => $order->customer_id,
        'store_id'       => $order->store_id,
        'order_id'       => $order->id,
        'amount'         => $reversalAmount,
        'invoice_number' => $order->invoice_number,
        'user_id'        => $user->id,
    ]);
}
$order->payments()
      ->whereIn('id', $creditPayments->pluck('id'))
      ->delete(); 

    $order->delete();
    });
}
}
