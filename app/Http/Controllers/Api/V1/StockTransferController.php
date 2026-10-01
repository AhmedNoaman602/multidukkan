<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\StockTransferService;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(protected StockTransferService $stockTransferService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StockTransfer::class);

        $user = auth()->user();

        $transfers = StockTransfer::where('tenant_id', $user->tenant_id)
            ->when($user->store_id, fn ($q) => $q->where('store_id', $user->store_id))
            ->when($request->store_id && ! $user->store_id, fn ($q) =>
                $q->where('store_id', $request->store_id))
            ->when($request->warehouse_id, fn ($q) =>
                $q->where(fn ($w) => $w->where('from_warehouse_id', $request->warehouse_id)
                    ->orWhere('to_warehouse_id', $request->warehouse_id)))
            ->when($request->product_id, fn ($q) =>
                $q->whereHas('items', fn ($i) => $i->where('product_id', $request->product_id)))
            ->when(in_array($request->type, [StockTransfer::TYPE_MANUAL, StockTransfer::TYPE_REPLENISHMENT], true), fn ($q) =>
                $q->where('type', $request->type))
            ->with('items.product:id,name,unit', 'fromWarehouse:id,name,type', 'toWarehouse:id,name,type', 'creator:id,name', 'order:id,invoice_number')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => StockTransferResource::collection($transfers)->resolve(),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'last_page'    => $transfers->lastPage(),
                'total'        => $transfers->total(),
            ],
        ]);
    }

    public function store(StoreStockTransferRequest $request)
    {
        $data = $request->validated();
        $from = Warehouse::findOrFail($data['from_warehouse_id']);

        $this->authorize('createFrom', [StockTransfer::class, $from]);

        $transfer = $this->stockTransferService->transfer($data, auth()->user());

        return (new StockTransferResource($transfer))
            ->response()
            ->setStatusCode(201);
    }

    public function show(StockTransfer $stockTransfer)
    {
        $this->authorize('view', $stockTransfer);

        return new StockTransferResource(
            $stockTransfer->load('items.product', 'fromWarehouse', 'toWarehouse', 'creator', 'order')
        );
    }
}
