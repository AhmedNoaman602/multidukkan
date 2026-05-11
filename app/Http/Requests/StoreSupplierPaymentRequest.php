<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Rules\BelongsToTenant;

class StoreSupplierPaymentRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer', new BelongsToTenant(Supplier::class, $tenantId)],
            'purchase_order_id' => ['nullable', 'integer', new BelongsToTenant(PurchaseOrder::class, $tenantId)],
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
