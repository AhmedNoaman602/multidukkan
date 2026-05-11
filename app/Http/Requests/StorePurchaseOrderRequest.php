<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Warehouse;
use App\Rules\BelongsToTenant;

class StorePurchaseOrderRequest extends FormRequest
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
        'supplier_id'          => ['required', 'integer', new BelongsToTenant(Supplier::class, $tenantId)],
        'notes'                => ['nullable', 'string'],
        'items'                => ['required', 'array', 'min:1'],
        'items.*.product_id'   => ['required', 'integer', new BelongsToTenant(Product::class, $tenantId)],
        'items.*.warehouse_id' => ['required', 'integer', new BelongsToTenant(Warehouse::class, $tenantId)],
        'items.*.quantity'     => ['required', 'integer', 'min:1'],
        'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
    ];
}
}
