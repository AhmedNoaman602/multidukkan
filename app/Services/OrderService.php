<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
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

    private const STAFF_MAX_DISCOUNT_PERCENT = 10;

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

            $customer = Customer::where('tenant_id', $user->tenant_id)
                ->findOrFail($data['customer_id']);

            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::where('tenant_id', $user->tenant_id)
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            abort_if($products->count() !== $productIds->count(), 404, 'Product not found.');

            // --- ITEM PREPARATION & PRICE CALCULATION ---
            // Normalize every line once: base-unit stock quantity + tier price.
            $validatedItems = [];
            foreach ($data['items'] as $itemData) {
                $product = $products[$itemData['product_id']];
                $warehouseId = $itemData['warehouse_id'] ?? null;
                $unitType = $itemData['unit_type'] ?? 'base';

                // Resolve the unit once; the item keeps this factor for every later edit or reversal.
                $conversionFactor = $product->factorFor($unitType);
                $stockQuantity = $itemData['quantity'] * $conversionFactor;

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
                : $price * $conversionFactor;

                // Temporarily store the validated data of the item to be processed after the order record is created.
                $validatedItems[] = [
                    'product' => $product,
                    'warehouseId' => $warehouseId,
                    'stockQty' => $stockQuantity,
                    'quantity' => $itemData['quantity'],
                    'unitType' => $unitType,
                    'conversionFactor' => $conversionFactor,
                    'unitName' => $product->unitNameFor($unitType),
                    'unitPrice' => $unitPrice,
                ];
            }

            // Stock is checked per product+warehouse, not per line: two lines for the
            // same product each pass on their own but can overdraw the shelf together.
            $aggregated = [];
            foreach ($validatedItems as $item) {
                if (!$item['warehouseId']) {
                    continue;
                }

                $key = $item['product']->id.'_'.$item['warehouseId'];

                $aggregated[$key] ??= [
                    'product_id' => $item['product']->id,
                    'warehouse_id' => $item['warehouseId'],
                    'stockQty' => 0,
                ];

                $aggregated[$key]['stockQty'] += $item['stockQty'];
            }

            foreach ($aggregated as $entry) {
                $this->inventory->checkStock(
                    $entry['product_id'],
                    $entry['warehouse_id'],
                    $entry['stockQty']
                );
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
                    'conversion_factor' => $v['conversionFactor'],
                    'unit_name' => $v['unitName'],
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

            // manual_total and discount are mutually exclusive: an override replaces the discount.
            $manualTotal = isset($data['manual_total']) ? round((float) $data['manual_total'], 2) : null;

            if ($manualTotal !== null) {
                $discount = 0;
                $chargeAmount = $manualTotal;
            } else {
                $discount = $this->resolveDiscount($data, $totalAmount, $user);
                $chargeAmount = round($totalAmount - $discount, 2);
            }

            // Update the order with its final total cost. The discount column carries the
            // resolved monetary amount — the subtotal is only known once items are priced,
            // so the create above could not clamp it or convert a percentage.
            $order->update([
                'discount'     => $discount,
                'manual_total' => $manualTotal,
                'total'        => $chargeAmount,
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

            if ($creditAvailable > 0) {
                $applyAmount = min($creditAvailable, $chargeAmount);

                $payment = Payment::create([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'amount' => $applyAmount,
                    'method' => 'credit',
                    'is_auto_reversible' => true,
                    'paid_at' => now(),
                ]);

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

            return $order;
        });
    }

    public function adjustItem(Order $order, OrderItem $item, array $data): void
    {
        DB::transaction(function () use ($order, $item, $data) {
        $this->ensureOrderIsEditable($order);

        $oldQty = $item->quantity;
        $newQty = $data['quantity'] ?? $oldQty;
        $delta = $newQty - $oldQty;

        $stockDelta = $delta * $item->conversion_factor;

        if ($stockDelta > 0) {
             $this->inventory->checkStock($item->product_id, $item->warehouse_id, $stockDelta);
            $this->inventory->deductStock(
                $item->product_id, $item->warehouse_id,
                $stockDelta, $order->id, Order::class, auth()->id()
            );
        } elseif ($stockDelta < 0) {
            $this->inventory->restoreStock(
                $item->product_id, $item->warehouse_id,
                abs($stockDelta), $order->id, Order::class, auth()->id()
            );
        }

        $item->update([
            'quantity' => $newQty,
            'unit_price' => $data['unit_price'] ?? $item->unit_price,
        ]);

        $this->resetToCalculatedTotal($order);
        });
    }

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

    private function resolveDiscount(array $data, float $subtotal, User $user): float
    {
        $value = (float) ($data['discount'] ?? 0);

        if (($data['discount_type'] ?? 'amount') === 'percent') {
            $value = round($subtotal * $value / 100, 2);
        }

        $value = max(0, min($value, $subtotal));

        if ($user->isStoreStaff() && $subtotal > 0) {
            $ceiling = round($subtotal * self::STAFF_MAX_DISCOUNT_PERCENT / 100, 2);

            if ($value > $ceiling) {
                throw ValidationException::withMessages([
                    'order' => __('messages.discount_exceeds_role_limit', [
                        'percent' => self::STAFF_MAX_DISCOUNT_PERCENT,
                        'max'     => $ceiling,
                    ]),
                ]);
            }
        }

        return $value;
    }

    private function recalculateTotal(Order $order): float
    {
        $subtotal = $order->items()->sum(DB::raw('unit_price * quantity'));
        $discount = (float) ($order->discount ?? 0);

        return max(0, round($subtotal - $discount, 2));
    }

    private function resetToCalculatedTotal(Order $order): void
    {
        $order->update(['manual_total' => null]);

        $this->ledger->adjustOrderCharge($order, $this->recalculateTotal($order));
    }

    public function addItem(Order $order, array $data)
    {
        return DB::transaction(function () use ($order, $data) {
        $this->ensureOrderIsEditable($order);

        $product = Product::findOrFail($data['product_id']);
        $warehouseId = $data['warehouse_id'];
        $unitType = $data['unit_type'] ?? 'base';
        $customer = $order->customer;

        $conversionFactor = $product->factorFor($unitType);
        $stockQuantity = $data['quantity'] * $conversionFactor;

        $this->inventory->checkStock($product->id, $warehouseId, $stockQuantity);

       $price = match ($customer->price_tier) {
                    'a' => $product->price_a ?? $product->price,
                    'b' => $product->price_b ?? $product->price,
                    'c' => $product->price_c ?? $product->price,
                    'd' => $product->price_d ?? $product->price,
                    'e' => $product->price_e ?? $product->price,
                    default => $product->price,
                };

        $unitPrice = $data['unit_price'] ?? $price * $conversionFactor;



     $existingItem = $order->items()
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouseId)
        ->where('unit_type', $unitType)
        ->where('conversion_factor', $conversionFactor)
        ->first();

    if ($existingItem) {
        $existingItem->update([
            'quantity' => $existingItem->quantity + $data['quantity'],
        ]);
    } else {
        $order->items()->create([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'warehouse_id' => $warehouseId,
            'quantity'     => $data['quantity'],
            'unit_price'   => $unitPrice,
            'unit_type'    => $unitType,
            'conversion_factor' => $conversionFactor,
            'unit_name'    => $product->unitNameFor($unitType),
        ]);
    }
          $this->inventory->deductStock($product->id, $warehouseId, $stockQuantity, $order->id, Order::class, auth()->id());

      $this->resetToCalculatedTotal($order);
    });
}

    // OrderService@updateOrder
public function updateOrder(Order $order, array $data, User $user): Order
{
    DB::transaction(function () use ($order, $data, $user) {
        $manualTotal = isset($data['manual_total']) ? round((float) $data['manual_total'], 2) : null;
        $hasDiscount = isset($data['discount']);
        $fields = array_diff_key($data, array_flip(['discount', 'discount_type', 'manual_total']));

        // Discount and manual_total change what the customer owes, so they go through the
        // payment guard. Notes/order_date carry no money implications.
        if ($manualTotal !== null) {
            $this->ensureOrderIsEditable($order);

            $order->update($fields + ['discount' => 0, 'manual_total' => $manualTotal]);
            $this->ledger->adjustOrderCharge($order, $manualTotal);
        } elseif ($hasDiscount) {
            $this->ensureOrderIsEditable($order);

            $subtotal = (float) $order->items()->sum(DB::raw('unit_price * quantity'));

            $order->update($fields + ['discount' => $this->resolveDiscount($data, $subtotal, $user)]);
            $this->resetToCalculatedTotal($order);
        } else {
            $order->update($fields);
        }
    });

    return $order->load('items', 'payments', 'customer');
}
  
   public function cancelOrder(Order $order, \App\Models\User $user): void
{
    DB::transaction(function () use ($order, $user) {
        $chargeAmount = $order->total;

        $hasUnrefundedPayments = $order->payments()
            ->cashOnly()
            ->whereRaw('amount > COALESCE(refunded_amount, 0)')
            ->exists();

        if ($hasUnrefundedPayments) {
            throw ValidationException::withMessages([
                'order' => __('messages.refund_before_cancel')
            ]);
        }

        $creditPayments = $order->payments()
    ->creditOnly()
    ->get();

    $creditPaymentsTotal = $creditPayments->sum('amount');

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

        $batchId = (string) Str::uuid();
        foreach ($order->items as $item) {
            if ($item->warehouse_id) {
                $this->inventory->restoreStock(
                    $item->product_id,
                    $item->warehouse_id,
                    $item->baseQuantity(),
                    $order->id,
                    Order::class,
                    $user->id,
                    $batchId
                );
            }
        }

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
