<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use Illuminate\Http\Request;
use App\Services\InventoryService;
use App\Http\Requests\AdjustInventoryRequest;
class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventoryService){}
    public function index()
    {
        $this->authorize('viewAny', Inventory::class);
        
        $user = auth()->user();
        $inventory = Inventory::where('tenant_id', $user->tenant_id)
            ->when($user->store_id, function ($q) use ($user) {
                $q->whereHas('warehouse', fn($w) => $w->where('store_id', $user->store_id));
            })
            ->get();
        return InventoryResource::collection($inventory);
    }
    public function show(Inventory $inventory)
    {
        $this->authorize('view', $inventory);
        
        if($inventory->tenant_id !== auth()->user()->tenant_id){
            return response()->json(['message'=>'Unauthorized'] , 403);
        }
        return new InventoryResource($inventory);
    }
    public function store(StoreInventoryRequest $request)
    {
        $this->authorize('create', Inventory::class);
        
        $user = auth()->user();
        $inventory = Inventory::create([
            'tenant_id' => $user->tenant_id,
            'warehouse_id' => $request->warehouse_id,
            'product_id' => $request->product_id,
            'quantity' => $request->quantity,
            'threshold'    => $request->threshold ?? 0,
        ]);
        return (new InventoryResource($inventory))
            ->response()
            ->setStatusCode(201);
    }
    public function update(UpdateInventoryRequest $request, Inventory $inventory)
    {
        $this->authorize('update', $inventory);
        
        if ($inventory->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $inventory->update($request->validated());
        return new InventoryResource($inventory);
    }

    public function adjust(AdjustInventoryRequest $request, Inventory $inventory)
    {
        $this->authorize('update', $inventory);
        
        $user = auth()->user();
        if ($inventory->tenant_id !== $user->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
       try {
        $this->inventoryService->adjustStock(
            $inventory->product_id,
            $inventory->warehouse_id,
            $request->quantity,
            $request->direction
        );
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
        return new InventoryResource($inventory->fresh());
    }
}
