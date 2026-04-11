<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\BelongsToTenant;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = auth()->user()->tenant_id;

        return [
            'store_id'             => $this->user()->store_id
                                        ? []
                                        : ['required', 'exists:stores,id', new BelongsToTenant(Store::class, $tenantId)],
            'customer_id'          => ['required', 'exists:customers,id', new BelongsToTenant(Customer::class, $tenantId)],
            'created_by'           => 'nullable|exists:users,id',
            'notes'                => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => ['required', 'exists:products,id', new BelongsToTenant(Product::class, $tenantId)],
            'items.*.warehouse_id' => ['required', 'exists:warehouses,id', new BelongsToTenant(Warehouse::class, $tenantId)],
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.unit_type' => 'nullable|in:base,secondary',
        ];
    }
}