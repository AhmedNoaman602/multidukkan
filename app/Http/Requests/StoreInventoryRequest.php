<?php

namespace App\Http\Requests;

use App\Rules\BelongsToTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Warehouse;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
class StoreInventoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = Auth::user()->tenant_id;
        return [
            'warehouse_id' => ['required', 'exists:warehouses,id', new BelongsToTenant(Warehouse::class, $tenantId)],
            'product_id'   => ['required', 'exists:products,id', new BelongsToTenant(Product::class, $tenantId)],
            'quantity' => 'required|integer|min:0',
            'threshold' => 'nullable|integer|min:0',
        ];
    }
}
