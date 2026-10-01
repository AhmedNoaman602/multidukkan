<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Rules\BelongsToTenant;
use App\Rules\WarehouseInStore;
use Illuminate\Foundation\Http\FormRequest;

// This endpoint creates manual transfers only. type, status and order_id are set by the
// server, and replenishment transfers are only ever created by the sale that needs them.
class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StockTransfer::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $fromStoreId = Warehouse::where('tenant_id', $tenantId)
            ->whereKey($this->integer('from_warehouse_id'))
            ->value('store_id');

        return [
            'from_warehouse_id'  => ['required', 'integer', new BelongsToTenant(Warehouse::class, $tenantId)],
            'to_warehouse_id'    => ['required', 'integer', 'different:from_warehouse_id', new BelongsToTenant(Warehouse::class, $tenantId), new WarehouseInStore($fromStoreId)],
            'notes'              => 'nullable|string|max:500',
            'items'              => 'required|array|min:1|max:50',
            'items.*.product_id' => ['required', 'integer', 'distinct', new BelongsToTenant(Product::class, $tenantId)],
            'items.*.quantity'   => 'required|integer|min:1|max:1000000',
            'items.*.unit_type'  => 'nullable|in:base,secondary',
            'type'               => 'prohibited',
            'status'             => 'prohibited',
            'order_id'           => 'prohibited',
        ];
    }

    public function messages(): array
    {
        return [
            'to_warehouse_id.different' => __('messages.transfer_same_location'),
        ];
    }
}
