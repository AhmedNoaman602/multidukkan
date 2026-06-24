<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\PurchaseOrder;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $query    = $request->q;
        $tenantId = auth()->user()->tenant_id;

        if (!$query || strlen($query) < 2) {
            return response()->json([
                'customers'       => [],
                'products'        => [],
                'orders'          => [],
                'suppliers'       => [],
                'purchase_orders' => [],
            ]);
        }

        $customers = Customer::where('tenant_id', $tenantId)
            ->where('is_walk_in', false)
            ->where(fn($q) => $q
                ->where('name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
                ->orWhere('code', 'like', "%{$query}%")
            )
            ->limit(3)
            ->get(['id', 'name', 'phone', 'code']);

        $products = Product::where('tenant_id', $tenantId)
            ->where(fn($q) => $q
                ->where('name', 'like', "%{$query}%")
                ->orWhere('sku', 'like', "%{$query}%")
            )
            ->limit(3)
            ->get(['id', 'name', 'sku', 'price']);

        $orders = Order::where('tenant_id', $tenantId)
            ->where(fn($q) => $q
                ->where('invoice_number', 'like', "%{$query}%")
                ->orWhere('customer_name_snapshot', 'like', "%{$query}%")
            )
            ->limit(3)
            ->get(['id', 'invoice_number', 'customer_name_snapshot', 'total']);

        $suppliers = Supplier::where('tenant_id', $tenantId)
            ->where(fn($q) => $q
                ->where('name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
                ->orWhere('code', 'like', "%{$query}%")
            )
            ->limit(3)
            ->get(['id', 'name', 'phone', 'code']);

        $purchaseOrders = PurchaseOrder::where('tenant_id', $tenantId)
            ->where(fn($q) => $q
                ->where('invoice_number', 'like', "%{$query}%")
                ->orWhere('supplier_name_snapshot', 'like', "%{$query}%")
            )
            ->limit(3)
            ->get(['id', 'invoice_number', 'supplier_name_snapshot', 'total']);

        return response()->json([
            'customers'       => $customers,
            'products'        => $products,
            'orders'          => $orders->map(fn($o) => [
                'id'             => $o->id,
                'invoice_number' => $o->invoice_number,
                'customer_name'  => $o->customer_name_snapshot,
                'total'          => $o->total,
            ]),
            'suppliers'       => $suppliers,
            'purchase_orders' => $purchaseOrders->map(fn($po) => [
                'id'             => $po->id,
                'invoice_number' => $po->invoice_number,
                'supplier_name'  => $po->supplier_name_snapshot,
                'total'          => $po->total,
            ]),
        ]);
    }
}