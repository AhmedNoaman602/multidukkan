<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Http\Resources\SupplierResource;

class SupplierController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Supplier::class);
        $user = auth()->user();
        $suppliers = Supplier::where('tenant_id', $user->tenant_id)->get();
        return SupplierResource::collection($suppliers);
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
        $supplier->delete();
        return response()->json(['message' => 'Supplier deleted successfully']);
    }

    private function generateSupplierCode(int $tenantId): string
    {
        $last = Supplier::where('tenant_id', $tenantId)
            ->whereNotNull('code')
            ->orderByDesc('id')
            ->value('code');

        $lastNumber = $last ? (int) substr($last, 2) : 0;
        $next = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);

        return "S-{$next}";
    }
}