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
        $warehouses = Warehouse::where('tenant_id', request()->tenant_id)
        ->where('store_id', request()->store_id)->get();
        return WarehouseResource::collection($warehouses);
    }
    public function store(StoreWarehouseRequest $request)
    {
        $warehouse = Warehouse::create($request->validated());
        return (new WarehouseResource($warehouse))
        ->response()
        ->setStatusCode(201);
    }
    public function show(Warehouse $warehouse)
    {
        return new WarehouseResource($warehouse);
    }
    public function update(StoreWarehouseRequest $request, Warehouse $warehouse)
    {
        $warehouse->update($request->validated());
        return new WarehouseResource($warehouse);
    }
    public function destroy(Warehouse $warehouse)
    {
        $hasInventory = $warehouse->inventories()->where('quantity', '>', 0)->exists();

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
