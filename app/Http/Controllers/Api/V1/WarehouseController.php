<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Warehouse;
use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Resources\WarehouseResource;
class WarehouseController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $warehouses = Warehouse::where('tenant_id', $user->tenant_id)
        ->when($user->store_id, fn($q) => $q->where('store_id', $user->store_id))
        ->get();
        return WarehouseResource::collection($warehouses);
    }
    public function store(StoreWarehouseRequest $request)
    {
        $user = auth()->user();
        $warehouse = Warehouse::create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $user->store_id ?? $request->store_id,
            'name' => $request->name,
            'location' => $request->location,
        ]);
        return (new WarehouseResource($warehouse))
        ->response()
        ->setStatusCode(201);
    }
    public function show(Warehouse $warehouse)
    {
        if($warehouse->tenant_id !== auth()->user()->tenant_id){
            return response()->json(['message'=>'Unauthorized'] , 403);
        }
        return new WarehouseResource($warehouse);
    }
    public function update(StoreWarehouseRequest $request, Warehouse $warehouse)
    {
         if ($warehouse->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $warehouse->update([
            'name'    => $request->name,
            'address' => $request->address,
            'phone'   => $request->phone,
            'email'   => $request->email,
        ]);
        return new WarehouseResource($warehouse);
    }
    public function destroy(Warehouse $warehouse)
    {
         if ($warehouse->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $hasInventory = $warehouse->inventories()
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasInventory) {
            return response()->json([
                'message' => 'Cannot delete warehouse with existing inventory.',
            ], 422);
        }

        $warehouse->delete();
        return response()->json([
            'message' => 'Warehouse deleted successfully',
        ]);
    }
}
