<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use Illuminate\Http\Request;
use App\Services\InventoryService;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventoryService){}
    public function index()
    {
        $inventory = Inventory::where('tenant_id', request()->tenant_id)
        ->where('warehouse_id', request()->warehouse_id)
        ->get();
        return InventoryResource::collection($inventory);
    }
    public function show(Inventory $inventory)
    {
        return new InventoryResource($inventory);
    }
    public function store(StoreInventoryRequest $request)
    {
        $inventory = Inventory::create($request->validated());
        return (new InventoryResource($inventory))
            ->response()
            ->setStatusCode(201);
    }
    public function update(UpdateInventoryRequest $request, Inventory $inventory)
    {
        if ($inventory->tenant_id !== (int) $request->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $inventory->update($request->validated());
        return new InventoryResource($inventory);
    }

    public function adjust(Request $request, Inventory $inventory)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        if ($inventory->tenant_id !== (int) $request->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $this->inventoryService->adjustStock(
            $inventory->warehouse_id,
            $inventory->product_id,          
            $request->quantity
        );
        return new InventoryResource($inventory->fresh());
    }
}
