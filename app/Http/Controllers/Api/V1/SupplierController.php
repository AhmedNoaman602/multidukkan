<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Models\Product;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\PurchaseOrderResource; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\LedgerService;

class SupplierController extends Controller
{
    public function __construct(protected LedgerService $ledger) {}

    public function index(Request $request)
{
    $this->authorize('viewAny', Supplier::class);

    $user = auth()->user();

    $query = Supplier::where('tenant_id', $user->tenant_id)
        ->when($request->search, function ($q) use ($request) {
            $q->where('name', 'like', "%$request->search%")
              ->orWhere('phone', 'like', "%$request->search%")
              ->orWhere('code', 'like', "%$request->search%");
        })
        ->orderByRaw("CASE WHEN code LIKE 'S-%' THEN 0 ELSE 1 END ASC")
        ->orderByRaw("CAST(SUBSTRING(code, 3) AS UNSIGNED) ASC");

    $supplierIds = (clone $query)->select('id');

    $debits = DB::table('ledger_entries')
        ->whereIn('supplier_id', $supplierIds)
        ->where('entity_type', 'supplier')
        ->where('direction', 'debit')
        ->sum('amount');

    $credits = DB::table('ledger_entries')
        ->whereIn('supplier_id', $supplierIds)
        ->where('entity_type', 'supplier')
        ->where('direction', 'credit')
        ->sum('amount');

    $totalOwed = max(0, round($debits - $credits, 2));

    $suppliers = $query->paginate(20);

    return response()->json([
        'data' => SupplierResource::collection($suppliers)->resolve(),
        'meta' => [
            'current_page' => $suppliers->currentPage(),
            'last_page'    => $suppliers->lastPage(),
            'total'        => $suppliers->total(),
        ],
        'stats' => [
            'total_suppliers' => $suppliers->total(),
            'total_owed'      => $totalOwed,
        ],
    ]);
}

    public function store(StoreSupplierRequest $request)
    {
        $this->authorize('create', Supplier::class);
        $user = auth()->user();

        $supplier = Supplier::create([
            'tenant_id' => $user->tenant_id,
            'code'      => $request->code ?? $this->generateSupplierCode($user->tenant_id),
            'name'      => $request->name,
            'phone'     => $request->phone,
            'email'     => $request->email,
            'address'   => $request->address,
            'area'      => $request->area,
            'notes'     => $request->notes,
        ]);

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Supplier $supplier)
    {
        $this->authorize('view', $supplier);
        if ($supplier->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $this->authorize('update', $supplier);
        if ($supplier->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $supplier->update($request->validated());
        return new SupplierResource($supplier);
    }

    public function destroy(Supplier $supplier)
    {
        $this->authorize('delete', $supplier);
        if ($supplier->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        try{
            $supplier->delete();
            return response()->json(['message' => 'Supplier deleted successfully.']);
        }catch(ValidationException $e){
            return response()->json(['message' => $e->errors()['supplier'][0]],422);    
        }
    }

  private function generateSupplierCode(int $tenantId): string
{
    $last = Supplier::where('tenant_id', $tenantId)
        ->whereNotNull('code')
        ->where('code', 'like', 'S-%')
        ->orderByRaw('CAST(SUBSTRING(code, 3) AS UNSIGNED) DESC')
        ->value('code');

    $lastNumber = $last ? (int) substr($last, 2) : 0;
    $next = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);

    return "S-{$next}";
}

    public function summary(Supplier $supplier)
{
    $this->authorize('view', $supplier);

    $tenantId = auth()->user()->tenant_id;
    $ledger   = app(LedgerService::class);

    return response()->json([
        'supplier_id'     => $supplier->id,
        'supplier_name'   => $supplier->name,
        'balance'         => $ledger->getSupplierBalance($tenantId, $supplier->id),
        'history'         => $ledger->getHistory($tenantId, null, $supplier->id),
        'purchase_orders' => PurchaseOrderResource::collection($supplier->purchaseOrders()
        ->with(['items', 'items.product', 'supplierPayments']) 
        ->orderByDesc('created_at')
        ->get()),
        'products' => $supplier->products()->with('inventories')->get(),
    ]);
}


    public function products(Supplier $supplier)
{
    $this->authorize('view', $supplier);

    if ($supplier->tenant_id !== auth()->user()->tenant_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $products = Product::where('supplier_id', $supplier->id)
        ->where('tenant_id', $supplier->tenant_id)
        ->with('inventories')
        ->get()
        ->map(fn($product) => [
            'id'          => $product->id,
            'name'        => $product->name,
            'sku'         => $product->sku,
            'unit'        => $product->unit,
            'price'       => $product->price,
            'total_stock' => $product->inventories->sum('quantity'),
        ]);

    return response()->json(['data' => $products]);
}
}