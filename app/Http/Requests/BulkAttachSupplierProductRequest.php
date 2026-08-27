<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkAttachSupplierProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'products'                => ['required', 'array', 'min:1'],
            'products.*.product_id'   => ['required', 'exists:products,id'],
            'products.*.cost_price'   => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'products.*.is_preferred' => ['sometimes', 'boolean'],
            'products.*.notes'        => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
