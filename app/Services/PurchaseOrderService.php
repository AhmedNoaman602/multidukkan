<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    /**
     * Create a new class instance.
     */
    public function __construct(protected InventoryService $inventory, protected LedgerService $ledger) {}

    private function generateInvoiceNumber(int $tenantId): string
    {
        $year = now()->year;

        $last = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
            ->whereNotNull('invoice_number')
            ->orderByDesc('id')
            ->value('invoice_number');

        $lastNumber = $last ? (int) substr($last, -3) : 0;
        $next = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);

        return "{$year}-{$next}";
    }

    public function createPurchaseOrder(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $user = auth()->user();

            // Tenant guard: fetch supplier + all requested products scoped to this tenant in one query each.
            // If any requested id doesn't come back, it either doesn't exist or belongs to another tenant —
            // either way we reject the whole PO instead of silently dropping the bad line.
            $supplier = Supplier::where('id', $data['supplier_id'])->where('tenant_id', $user->tenant_id)->first();
            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::whereIn('id', $productIds)->where('tenant_id', $user->tenant_id)->get()->keyBy('id');

            if ($products->count() !== $productIds->count()) {

                throw new \InvalidArgumentException('One or more products do not belong to this tenant.');

            }
            if (!$supplier) {

                 throw new \InvalidArgumentException('Supplier does not belong to this tenant.');

            }

            // Normalize each raw item line into base units: convert quantity/price when the line
            // was entered in a "secondary" unit (e.g. box) using the product's conversion_factor,
            // so everything downstream works in base units consistently.
            $validatedItems = [];
            foreach ($data['items'] as $itemData) {
                $product = $products->get($itemData['product_id']);
                $warehouseId = $itemData['warehouse_id'] ?? null;
                $unitType = $itemData['unit_type'] ?? 'base';

                $stockQuantity = $unitType === 'secondary' && $product->conversion_factor
                    ? $itemData['quantity'] * $product->conversion_factor
                    : $itemData['quantity'];

                $price = $itemData['unit_price'];
                $unitPrice = $unitType === 'secondary' && $product->conversion_factor
                    ? $price * $product->conversion_factor
                    : $price;

                $validatedItems[] = [
                    'product' => $product,
                    'warehouseId' => $warehouseId,
                    'stockQty' => $stockQuantity,
                    'quantity' => $itemData['quantity'],
                    'unitType' => $unitType,
                    'unitPrice' => $unitPrice,
                ];
            }

            // Create the PO header shell first (total is a placeholder, filled in once items are totaled below).
            $purchaseOrder = PurchaseOrder::create([
                'tenant_id' => $user->tenant_id,
                'supplier_id' => $data['supplier_id'],
                'supplier_name_snapshot' => $supplier?->name,
                'created_by' => $user->id,
                'notes' => $data['notes'] ?? null,
                'invoice_number' => $this->generateInvoiceNumber($user->tenant_id),
                'total' => 0,
            ]);

            $productIds = collect($validatedItems)
                ->pluck('product.id')
                ->unique()
                ->values();

            $stockMap = Inventory::whereIn('product_id', $productIds)
                ->selectRaw('product_id, SUM(quantity) as total_stock')
                ->groupBy('product_id')
                ->pluck('total_stock', 'product_id');


            // Pre-build the supplier_products pivot payload for every item up front, so the
            // actual sync call can happen once after the loop instead of once per item.
            $syncData = [];
            foreach($validatedItems as $v) {
                $syncData[$v['product']->id] = [
                    'last_purchase_price' => $v['unitPrice'],
                    'last_purchased_at' => now(),
                ];
            }

            $runningStock = [];
            $runningCost = [];

            // Main line-item loop: create each PO item row, recompute the product's weighted-average
            // cost_price, push stock into inventory (if a warehouse was given), and accumulate the
            // PO's total.
            $totalAmount = 0;
            foreach ($validatedItems as $v) {
                $purchaseOrderItem = $purchaseOrder->items()->create([
                    'product_id' => $v['product']->id,
                    'product_name' => $v['product']->name,
                    'quantity' => $v['quantity'],
                    'unit_type' => $v['unitType'],
                    'unit_price' => $v['unitPrice'],
                    'warehouse_id' => $v['warehouseId'],
                    'total' => $v['unitPrice'] * $v['quantity'],
                ]);

                // Weighted-average cost math: blends existing stock (at its existing cost) with the
                // newly purchased quantity (at this line's price). See BUG note above on $stockMap —
                // $currentStock here is always the pre-PO snapshot, never "stock after a prior line
                // in this same PO already ran".
                $currentStock = $runningStock[$v['product']->id] ?? $stockMap[$v['product']->id] ?? 0;
                $currentCost = $runningCost[$v['product']->id] ?? $v['product']->cost_price ?? $v['unitPrice'];
                $effectiveStock = $currentStock;

                if ($effectiveStock + $v['stockQty'] > 0) {
                    $newAvg = ($effectiveStock * $currentCost + $v['stockQty'] * $v['unitPrice'])
                              / ($effectiveStock + $v['stockQty']);
                } else {
                    $newAvg = $v['unitPrice'];
                }
                $v['product']->update(['cost_price' => round($newAvg, 2)]);
                $runningStock[$v['product']->id] = $effectiveStock + $v['stockQty'];
                $runningCost[$v['product']->id] = round($newAvg, 2);
                // Only push stock into inventory if this line specified a warehouse (warehouse_id
                // is nullable on order/PO items by design — no warehouse means skip the stock move).
                if ($v['warehouseId']) {
                    $this->inventory->restoreStock(
                        $v['product']->id,
                        $v['warehouseId'],
                        $v['stockQty'],
                        $purchaseOrder->id,
                        PurchaseOrder::class
                    );
                }
                $totalAmount += ($purchaseOrderItem->unit_price * $purchaseOrderItem->quantity);
            }

            // Single batched pivot sync (built above) instead of one sync call per item.
            $supplier->products()->syncWithoutDetaching($syncData);
            $purchaseOrder->update(['total' => round($totalAmount, 2)]);

            $this->ledger->purchaseCharge([
                'tenant_id' => $purchaseOrder->tenant_id,
                'supplier_id' => $purchaseOrder->supplier_id,
                'purchase_order_id' => $purchaseOrder->id,
                'invoice_number' => $purchaseOrder->invoice_number,
                'total' => $purchaseOrder->total,
            ]);

            return $purchaseOrder;
        });
    }

    public function cancelPurchaseOrder(PurchaseOrder $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder) {
            $subtotal = $purchaseOrder->items()
                ->sum(DB::raw('unit_price * quantity'));

            $chargeAmount = max(0, round($subtotal, 2));

            foreach ($purchaseOrder->items as $item) {
                if ($item->warehouse_id) {
                    $stockQuantity = $item->unit_type === 'secondary' && $item->product->conversion_factor
                        ? $item->quantity * $item->product->conversion_factor
                        : $item->quantity;

                    $this->inventory->deductStock($item->product_id, $item->warehouse_id, $stockQuantity, $purchaseOrder->id, PurchaseOrder::class);
                }
            }
            $this->ledger->reversePurchaseOrder([
                'tenant_id' => $purchaseOrder->tenant_id,
                'supplier_id' => $purchaseOrder->supplier_id,
                'purchase_order_id' => $purchaseOrder->id,
                'invoice_number' => $purchaseOrder->invoice_number,
                'amount' => $chargeAmount,
            ]);

            $purchaseOrder->delete();
        });
    }
}
