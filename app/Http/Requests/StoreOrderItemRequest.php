<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Product;
use App\Models\Warehouse;
use App\Rules\BelongsToTenant;

class StoreOrderItemRequest extends FormRequest
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
        $tenantId = auth()->user()->tenant_id;

        return [
            'product_id'   => ['required', 'integer', new BelongsToTenant(Product::class, $tenantId)],
            'warehouse_id' => ['required', 'integer', new BelongsToTenant(Warehouse::class, $tenantId)],
            'quantity'     => ['required', 'numeric', 'min:1'],
            'unit_type'    => ['nullable', 'in:base,secondary'],
            'unit_price'   => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }
}
