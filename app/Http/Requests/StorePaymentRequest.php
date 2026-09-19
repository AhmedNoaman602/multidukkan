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
        $user     = Auth::user();
        $tenantId = $user->tenant_id;

        $orderExists = Rule::exists('orders', 'id')
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenantId);

        if ($user->store_id !== null) {
            $orderExists->where('store_id', $user->store_id);
        }

        return [
            'order_id'    => [
                'required',
                $orderExists,
                new OrderBelongsToCustomer($this->input('customer_id'))
            ],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'amount'      => 'required|numeric|min:0.01|max:99999999.99|decimal:0,2',
            'method'      => 'required|in:cash,bank_transfer,instapay,vodafone_cash,orange_cash,check',
            'payment_reference' => 'nullable|string|max:255',
        ];
    }
}