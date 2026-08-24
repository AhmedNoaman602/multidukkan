<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\LocalDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request){
        $user = auth()->user();

        if($user->role !== 'tenant_admin'){
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        // created_at is stored in UTC, but date_from/date_to are the viewer's local
        // calendar dates. Translate them into the UTC instants that bound those local
        // days once, here, and reuse for all three union arms. Comparing the raw column
        // (rather than whereDate's DATE(created_at)) also keeps the index usable.
        $timezone = LocalDateRange::timezoneFor($request);
        $from = LocalDateRange::startOfDay($request->date_from, $timezone);
        $to   = LocalDateRange::endOfDay($request->date_to, $timezone);

        $applyDateRange = function ($query) use ($from, $to) {
            if ($from) {
                $query->where('created_at', '>=', $from);
            }
            if ($to) {
                $query->where('created_at', '<=', $to);
            }
        };

        $ledgerQuery = DB::table('ledger_entries')
            ->selectRAW('id, type, amount, description, reference_type, reference_id, customer_id, "ledger" as source, null as quantity , null as product_id, null as warehouse_id, user_id, Case WHEN entity_type = "supplier" THEN entity_id ELSE null END as supplier_id, null as changes, created_at, null as batch_id, null as item_count')
            ->where('tenant_id', $user->tenant_id);

        $applyDateRange($ledgerQuery);

        // Grouped by batch_id so an order/PO's line-item stock movements show as one event
        // instead of one row per product — falls back to one group per row when batch_id is
        // null (e.g. a single-item adjustItem edit, or data written before this column existed).
        $inventoryQuery = DB::table('inventory_transactions')
            ->selectRAW('COALESCE(batch_id, CAST(id AS CHAR)) as id, MAX(type) as type, null as amount, null as description, MAX(reference_type) as reference_type, MAX(reference_id) as reference_id, null as customer_id, "inventory" as source, SUM(quantity) as quantity, MIN(product_id) as product_id, MIN(warehouse_id) as warehouse_id, MAX(user_id) as user_id, null as supplier_id, null as changes, MAX(created_at) as created_at, MAX(batch_id) as batch_id, COUNT(DISTINCT product_id) as item_count')
            ->where('tenant_id', $user->tenant_id);

        $applyDateRange($inventoryQuery);

        $inventoryQuery->groupByRaw('COALESCE(batch_id, CAST(id AS CHAR))');

        $auditQuery = DB::table('audit_logs')
            ->selectRAW('id, action as type, null as amount, null as description, auditable_type as reference_type, auditable_id as reference_id, null as customer_id, "audit" as source, null as quantity, null as product_id, null as warehouse_id, user_id, null as supplier_id, changes, created_at, null as batch_id, null as item_count')
            ->where('tenant_id', $user->tenant_id);

        $applyDateRange($auditQuery);

        $combined = match($request->source) {
            'ledger'    => $ledgerQuery,
            'inventory' => $inventoryQuery,
            'audit'     => $auditQuery,
            default     => $ledgerQuery->unionAll($inventoryQuery)->unionAll($auditQuery),
        };

        $entries = $combined->orderByDesc('created_at')->paginate($request->per_page ?? 20);

        $rows = collect($entries->items());

        $customerIds = $rows->pluck('customer_id')->filter()->unique();
        $customers = Customer::whereIn('id', $customerIds)->pluck('name', 'id');

        $supplierIds = $rows->pluck('supplier_id')->filter()->unique();
        $suppliers = Supplier::whereIn('id', $supplierIds)->pluck('name', 'id');

        $productIds = $rows->pluck('product_id')->filter()->unique();
        $products = Product::whereIn('id', $productIds)->pluck('name', 'id');

        $warehouseIds = $rows->pluck('warehouse_id')->filter()->unique();
        $warehouses = Warehouse::whereIn('id', $warehouseIds)->pluck('name', 'id');

        $userIds = $rows->pluck('user_id')->filter()->unique();
        $users = User::whereIn('id', $userIds)->pluck('name', 'id');

        // Auditable entity labels — scoped to source='audit'/'inventory' rows, since both use
        // real FQCN reference_type values (Order::class, PurchaseOrder::class, ...); ledger rows
        // use unrelated plain strings ('order', 'payment') and are excluded.
        $auditRows = $rows->whereIn('source', ['audit', 'inventory']);

        $auditProductIds = $auditRows->where('reference_type', Product::class)->pluck('reference_id')->filter()->unique();
        $auditProductNames = Product::whereIn('id', $auditProductIds)->pluck('name', 'id');

        $auditCustomerIds = $auditRows->where('reference_type', Customer::class)->pluck('reference_id')->filter()->unique();
        $auditCustomerNames = Customer::whereIn('id', $auditCustomerIds)->pluck('name', 'id');

        $auditSupplierIds = $auditRows->where('reference_type', Supplier::class)->pluck('reference_id')->filter()->unique();
        $auditSupplierNames = Supplier::whereIn('id', $auditSupplierIds)->pluck('name', 'id');

        $auditStoreIds = $auditRows->where('reference_type', Store::class)->pluck('reference_id')->filter()->unique();
        $auditStoreNames = Store::whereIn('id', $auditStoreIds)->pluck('name', 'id');

        $auditOrderIds = $auditRows->where('reference_type', Order::class)->pluck('reference_id')->filter()->unique();
        $auditOrderNames = Order::whereIn('id', $auditOrderIds)->pluck('invoice_number', 'id');

        $auditPurchaseOrderIds = $auditRows->where('reference_type', PurchaseOrder::class)->pluck('reference_id')->filter()->unique();
        $auditPurchaseOrderNames = PurchaseOrder::whereIn('id', $auditPurchaseOrderIds)->pluck('invoice_number', 'id');

        $data = $rows->map(function ($row) use (
            $customers, $suppliers, $products, $warehouses, $users,
            $auditProductNames, $auditCustomerNames, $auditSupplierNames, $auditStoreNames, $auditOrderNames, $auditPurchaseOrderNames
        ) {
            $auditableType = null;
            $entityName = null;

            if (in_array($row->source, ['audit', 'inventory'])) {
                $auditableType = class_basename($row->reference_type);
                $entityName = match ($row->reference_type) {
                    Product::class       => $auditProductNames[$row->reference_id] ?? null,
                    Customer::class      => $auditCustomerNames[$row->reference_id] ?? null,
                    Supplier::class      => $auditSupplierNames[$row->reference_id] ?? null,
                    Store::class         => $auditStoreNames[$row->reference_id] ?? null,
                    Order::class         => $auditOrderNames[$row->reference_id] ?? null,
                    PurchaseOrder::class => $auditPurchaseOrderNames[$row->reference_id] ?? null,
                    default              => null,
                };
            }

            $itemCount = $row->item_count !== null ? (int) $row->item_count : null;

            // Sales/returns never carry notes (that's reserved for manual adjustments) — build a
            // reasonable description from the product(s) + the order/PO that moved the stock instead.
            $stockLabel = $itemCount > 1
                ? __('messages.product_count', ['count' => $itemCount])
                : ($products[$row->product_id] ?? __('messages.product_fallback_label'));

            $description = $row->description ?? match ($row->source) {
                'audit'     => trim(($entityName ?? $auditableType) . ' ' . $row->type),
                'inventory' => trim($stockLabel . ($entityName ? " — {$entityName}" : '')),
                default     => null,
            };

            return [
                'id'             => $row->id,
                'source'         => $row->source,
                'type'           => $row->type,
                'amount'         => $row->amount !== null ? (float) $row->amount : null,
                'quantity'       => $row->quantity !== null ? (int) $row->quantity : null,
                'description'    => $description,
                'customer_name'  => $customers[$row->customer_id] ?? null,
                'supplier_name'  => $suppliers[$row->supplier_id] ?? null,
                'product_name'   => $products[$row->product_id] ?? null,
                'warehouse_name' => $warehouses[$row->warehouse_id] ?? null,
                'user_name'      => $users[$row->user_id] ?? null,
                'auditable_type' => $auditableType,
                'entity_name'    => $entityName,
                'changes'        => $row->changes ? json_decode($row->changes, true) : null,
                // Raw DB::table() hands back an unmarked string; without an explicit Z the
                // browser parses it as local time and renders it hours off. Microseconds are
                // kept — feed ordering relies on them (see LedgerEntry::$dateFormat).
                'created_at'     => Carbon::parse($row->created_at, 'UTC')->toIso8601ZuluString('microsecond'),
                'batch_id'       => $row->batch_id,
                'item_count'     => $itemCount,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }

    /**
     * Itemized product breakdown for a grouped inventory activity row — fetched on demand
     * when the drawer opens a batched sale/restock event.
     */
    public function inventoryBatch(string $batchId)
    {
        $user = auth()->user();

        if ($user->role !== 'tenant_admin') {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        $items = InventoryTransaction::where('tenant_id', $user->tenant_id)
            ->where('batch_id', $batchId)
            ->with('product:id,name', 'warehouse:id,name')
            ->get()
            ->map(fn ($t) => [
                'product_name'   => $t->product?->name ?? __('messages.deleted_product'),
                'warehouse_name' => $t->warehouse?->name ?? '—',
                'quantity'       => $t->quantity,
            ]);

        if ($items->isEmpty()) {
            return response()->json(['message' => __('messages.batch_not_found')], 404);
        }

        return response()->json(['data' => $items]);
    }
}
