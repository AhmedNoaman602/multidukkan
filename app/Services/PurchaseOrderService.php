<?php

namespace App\Services;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\InventoryService;
use App\Services\LedgerService;

class PurchaseOrderService
{
    /**
     * Create a new class instance.
     */
    public function __construct(protected InventoryService $inventory , protected LedgerService $ledger){
        
    }

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
            
           $validatedItems = [];
           foreach($data['items'] as $itemData){
                $product = Product::findOrFail($itemData['product_id']);
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
                'product'      => $product,
                'warehouseId'  => $warehouseId,
                'stockQty'     => $stockQuantity,
                'quantity'     => $itemData['quantity'],
                'unitType'     => $unitType,
                'unitPrice'    => $unitPrice,
            ];
           }
           $purchaseOrder = PurchaseOrder::create([
            'tenant_id'   => $user->tenant_id,
            'supplier_id' => $data['supplier_id'],
            'supplier_name_snapshot'  => Supplier::find($data['supplier_id'])?->name,
            'created_by'  => $user->id,
            'notes'       => $data['notes'] ?? null,
            'invoice_number' => $this->generateInvoiceNumber($user->tenant_id),
            'total'       => 0,
           ]);

           $totalAmount=0;
           foreach($validatedItems as $v){
            $purchaseOrderItem = $purchaseOrder->items()->create([
                'product_id' => $v['product']-> id,
                'product_name' => $v['product']-> name,
                'quantity' => $v['quantity'],
                'unit_type' => $v['unitType'],
                'unit_price' => $v['unitPrice'],
                'warehouse_id' => $v['warehouseId'],
                'total'        => $v['unitPrice'] * $v['quantity'],
            ]);
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
           $purchaseOrder->update(['total' => round($totalAmount, 2)]);

           $this->ledger->purchaseCharge([
            'tenant_id'   => $purchaseOrder->tenant_id,
            'supplier_id' => $purchaseOrder->supplier_id,
            'purchase_order_id'=> $purchaseOrder->id,
            'invoice_number'=> $purchaseOrder->invoice_number,
            'total'      => $purchaseOrder->total,
        ]);
            return $purchaseOrder;
        });
    }

    public function cancelPurchaseOrder(PurchaseOrder $purchaseOrder) : void {
        DB::transaction(function () use ($purchaseOrder) {
        $subtotal = $purchaseOrder->items()
            ->sum(DB::raw('unit_price * quantity'));
        
        $chargeAmount = max(0, round($subtotal , 2));

       foreach ($purchaseOrder->items as $item) {
    if ($item->warehouse_id) {
        $stockQuantity = $item->unit_type === 'secondary' && $item->product->conversion_factor
            ? $item->quantity * $item->product->conversion_factor
            : $item->quantity;

        $this->inventory->deductStock($item->product_id, $item->warehouse_id, $stockQuantity, $purchaseOrder->id, PurchaseOrder::class);
    }
}
        $this->ledger->reversePurchaseOrder([
            'tenant_id'   => $purchaseOrder->tenant_id,
            'supplier_id' => $purchaseOrder->supplier_id,
            'purchase_order_id'    => $purchaseOrder->id,
            'invoice_number' => $purchaseOrder->invoice_number,
            'amount'      => $chargeAmount,
        ]);

        $purchaseOrder->delete();
    });
}
}