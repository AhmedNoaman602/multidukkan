<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\LocalDateRange;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
    public function __construct(
        protected LedgerService $ledger,
        protected InventoryService $inventory,
        protected StockFulfillmentService $fulfillment,
    ) {}

    // Every sale is taken from the store's shelf (ADR-010).
    private function shelfOf(int $tenantId, int $storeId): Warehouse
    {
        return Warehouse::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('type', Warehouse::TYPE_SHELF)
            ->first()
            ?? throw ValidationException::withMessages(['store' => __('messages.store_has_no_shelf')]);
    }

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
        $user = auth()->user();
        $this->inventory->ensureStockRows(
            collect($data['items'])->pluck('product_id')->unique()->values()->all(),
            $this->shelfOf($user->tenant_id, $user->store_id ?? $data['store_id'])->id,
            $user->tenant_id,
            0
        );

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

            abort_if($products->count() !== $productIds->count(), 404, __('messages.product_not_found'));

            $storeId = $user->store_id ?? $data['store_id'];
            $shelf = $this->shelfOf($user->tenant_id, $storeId);

            // --- ITEM PREPARATION & PRICE CALCULATION ---
            // Normalize every line once: base-unit stock quantity + tier price.
            $validatedItems = [];
            $baseNeeds = [];
            foreach ($data['items'] as $itemData) {
                $product = $products[$itemData['product_id']];
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
                    'stockQty' => $stockQuantity,
                    'quantity' => $itemData['quantity'],
                    'unitType' => $unitType,
                    'conversionFactor' => $conversionFactor,
                    'unitName' => $product->unitNameFor($unitType),
                    'unitPrice' => $unitPrice,
                ];

                // Stock is needed per product, not per line: 1 box + 5 pcs is one need of 17.
                $baseNeeds[$product->id] = ($baseNeeds[$product->id] ?? 0) + $stockQuantity;
            }

            // --- ORDER CREATION BLOCK ---
            // Create the main Order record with tenant, store, customer, creator, and generated invoice number details.
            $order = Order::create([
                'tenant_id' => $user->tenant_id,
                'store_id' => $storeId,
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
    // Only merges truly identical rows: same product, unit, conversion factor and unit price
    $key = $v['product']->id . '_' . $v['unitType'] . '_' . $v['conversionFactor'] . '_' . $this->priceKey($v['unitPrice']);

    if (isset($mergedItems[$key])) {
        $mergedItems[$key]['quantity'] += $v['quantity'];
        $mergedItems[$key]['stockQty'] += $v['stockQty'];
    } else {
        $mergedItems[$key] = $v;
    }
}

            // --- STOCK, THEN ORDER ITEMS ---
            // fulfill() locks stock, then products, before anything references them (D31/P4),
            // refills the shelf from storage if needed and deducts the sale. Lines are inserted
            // afterwards, each with the cost per base unit read under that lock (D33).
            $costs = $this->fulfillment->fulfill($order, $shelf, $baseNeeds, $user, $batchId);

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
                    'unit_cost_at_sale' => $costs[$v['product']->id],
                    'warehouse_id' => $shelf->id,
                ]);

                $totalAmount += ($orderItem->unit_price * $orderItem->quantity);
            }
            // Rounded to the price scale so a percent discount is calculated on an exact subtotal.
            $totalAmount = round($totalAmount, Money::PRICE_SCALE);

            // --- LEDGER & CREDIT CHARGING BLOCK ---
            // Check the customer's current balance before processing this order.
            // A negative balance indicates the customer has an active credit line available.
            // The shared walk-in account never spends credit, so its invoices stay cancellable in cash.
             $creditAvailable = $customer->is_walk_in
                 ? 0
                 : max(0, -$this->ledger->getBalance($order->tenant_id, $order->customer_id));

            // manual_total and discount are mutually exclusive: an override replaces the discount.
            $manualTotal = isset($data['manual_total']) ? round((float) $data['manual_total'], 2) : null;

            if ($manualTotal !== null) {
                $discount = 0;
                $chargeAmount = $manualTotal;
            } else {
                $discount = $this->resolveDiscount($data, $totalAmount, $user);
                $chargeAmount = Money::total($totalAmount - $discount);
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
                    'counts_as_cash' => false,
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
            'counts_as_cash'     => true,
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
        }, attempts: 3);
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

    private function priceKey(float $price): string
    {
        return number_format(round($price, Money::PRICE_SCALE), Money::PRICE_SCALE, '.', '');
    }

    /**
     * D32: a created invoice is final. Only notes and order_date can still change; anything
     * that changes what the customer owes (discount, manual_total) is refused.
     */
    public function updateOrder(Order $order, array $data, User $user): Order
    {
        if (array_intersect_key($data, array_flip(['discount', 'discount_type', 'manual_total'])) !== []) {
            throw ValidationException::withMessages([
                'order' => __('messages.order_is_final'),
            ]);
        }

        $order = DB::transaction(function () use ($order, $data) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if (isset($data['order_date'])
                && Carbon::parse($data['order_date'])->toDateString() !== $order->order_date
                && ! $order->createdToday()) {
                throw ValidationException::withMessages([
                    'order' => __('messages.order_date_locked'),
                ]);
            }

            $order->update($data);

            return $order;
        }, attempts: 3);

        return $order->load('items', 'payments', 'customer');
    }
  
    /**
     * Cancel an invoice and settle its money in the same transaction.
     *
     * Always: store credit back (CREDIT_APPLY), the rest of the charge reversed (REVERSAL),
     * stock back at each line's sale cost. Whatever was paid, net of earlier refunds, is then
     * the customer's credit. $settlement decides that whole amount: 'credit' writes nothing
     * more; 'cash' refunds the cash payments and pays the restored store credit out.
     *
     * Locks: customer (L0) → order → payments → stock rows → products. The customer lock is
     * the first statement, so every read after it sees what the previous holder committed.
     */
    public function cancelOrder(Order $order, User $user, ?string $settlement = null): void
    {
        $customerId = $order->customer_id;

        DB::transaction(function () use ($order, $user, $settlement, $customerId) {
            Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();

            $order = Order::withTrashed()->lockForUpdate()->findOrFail($order->id);
            if ($order->trashed()) {
                throw ValidationException::withMessages([
                    'order' => __('messages.order_already_cancelled'),
                ]);
            }

            $order->setRelation('payments', $order->payments()->orderBy('id')->lockForUpdate()->get());
            $summary = $this->ledger->cancellationSummary($order);

            if ($summary['blocked_reason'] === 'return_offset') {
                throw ValidationException::withMessages([
                    'order' => __('messages.cancel_has_return_offset'),
                ]);
            }

            $settleInCash = $summary['settlement_required'] && $settlement === 'cash';

            if ($summary['settlement_required']) {
                if ($settlement === null) {
                    throw ValidationException::withMessages([
                        'settlement' => __('messages.cancel_settlement_required'),
                    ]);
                }
                if (! in_array($settlement, $summary['settlements'], true)) {
                    throw ValidationException::withMessages([
                        'settlement' => __('messages.cancel_walk_in_cash_only'),
                    ]);
                }
                if ($settleInCash && ! $summary['cash_payout_available']) {
                    throw ValidationException::withMessages([
                        'settlement' => __('messages.cancel_credit_payout_unavailable', [
                            'amount'    => number_format($summary['credit_paid'], 2, '.', ''),
                            'available' => number_format($summary['available_for_payout'], 2, '.', ''),
                        ]),
                    ]);
                }
            }

            $entry = [
                'tenant_id'      => $order->tenant_id,
                'customer_id'    => $order->customer_id,
                'store_id'       => $order->store_id,
                'order_id'       => $order->id,
                'invoice_number' => $order->invoice_number,
                'user_id'        => $user->id,
            ];

            foreach ($order->payments->where('is_auto_reversible', true) as $payment) {
                $this->ledger->restoreCredit($entry + [
                    'payment_id' => $payment->id,
                    'amount'     => $payment->amount,
                ]);
            }

            // P2: each line goes back at its own sale cost (unit_cost_at_sale); stock rows and
            // products are locked together in id order by restoreAtCost (never line by line).
            // An unknown cost goes back at today's average (D27).
            $lines = $order->items()->get()->map(fn ($item) => [
                'product_id'    => $item->product_id,
                'warehouse_id'  => $item->warehouse_id,
                'base_quantity' => $item->baseQuantity(),
                'unit_cost'     => $item->unit_cost_at_sale,
            ])->all();

            if ($lines !== []) {
                $this->inventory->restoreAtCost($lines, $order->id, Order::class, $user->id, (string) Str::uuid());
            }

            $reversalAmount = round($summary['total'] - $summary['credit_paid'], 2);
            if ($reversalAmount > 0) {
                $this->ledger->reverseOrder($entry + ['amount' => $reversalAmount]);
            }

            if ($settleInCash && $summary['cash_paid'] > 0) {
                $this->ledger->issueRefund($entry + [
                    'amount' => $summary['cash_paid'],
                    'method' => 'cash',
                    'notes'  => 'cancelled order '.$order->invoice_number,
                ]);
            }

            if ($settleInCash && $summary['credit_paid'] > 0) {
                $this->ledger->payOutCredit($entry + [
                    'amount'         => $summary['credit_paid'],
                    'reference_type' => 'order',
                    'reference_id'   => $order->id,
                    'description'    => 'Store credit paid out — cancelled order '.$order->invoice_number,
                ]);
            }

            $order->delete();
        }, attempts: 3);
    }
}
