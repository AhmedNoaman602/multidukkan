<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cost_price'   => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'is_preferred' => ['sometimes', 'boolean'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
