<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\OrderBelongsToCustomer;
use Illuminate\Support\Facades\Auth;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = Auth::user()->tenant_id;

        return [
            'order_id'    => [
                'required',
                Rule::exists('orders', 'id')->whereNull('deleted_at'),
                new OrderBelongsToCustomer($this->input('customer_id'))
            ],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'required|in:cash,bank_transfer,credit',
        ];
    }
}