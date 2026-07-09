<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Customer;
use App\Rules\BelongsToTenant;

class AutoPaymentRequest extends FormRequest
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
            'customer_id' => ['required', 'integer', new BelongsToTenant(Customer::class, $tenantId)],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'method'      => ['required', 'in:cash,bank_transfer,instapay,vodafone_cash,orange_cash,check'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
