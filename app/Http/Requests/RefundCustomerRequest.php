<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Services\LedgerService;
use App\Models\LedgerEntry;
use App\Models\Payment;

class RefundCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function __construct(protected LedgerService $ledgerService) 
{
    parent::__construct();
}

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
    return [
        'amount'  => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
        'method'  => ['required', 'in:cash,bank_transfer,check'],
        'notes'   => ['nullable', 'string', 'max:500'],
        'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        'payment_id_target'  => ['nullable', 'integer', 'exists:payments,id'], 

    ];
}

public function withValidator($validator): void
{
    $validator->after(function ($validator) {
        $customer   = $this->route('customer');
        $tenantId   = $customer->tenant_id;
        $customerId = $customer->id;

        if ($this->payment_id_target) {
            $payment = Payment::find($this->payment_id_target);
            if (!$payment || (int) $payment->customer_id !== (int) $customerId) {
                $validator->errors()->add('payment_id_target', 'Payment does not belong to this customer.');
                return;
            }

            if ($payment->is_auto_reversible) {
                $validator->errors()->add('payment_id_target', 'Credit payments cannot be refunded as cash. Cancel the order instead.');
                return;
            }

            $refundable = $this->ledgerService->refundableForPayment($payment);
            if ($this->amount > $refundable) {
                $validator->errors()->add('amount', "Cannot exceed {$refundable} EGP for this payment.");
            }
            return;
        }

        if ($this->order_id) {
            $order = \App\Models\Order::find($this->order_id);
            if (!$order || (int) $order->tenant_id !== (int) $tenantId) {
                $validator->errors()->add('order_id', 'Order does not belong to this tenant.');
                return;
            }

            $refundable = $this->ledgerService->refundableForOrder($this->order_id);

            if ($refundable <= 0) {
                $validator->errors()->add('amount', 'This order has already been fully refunded.');
                return;
            }

            if ($this->amount > $refundable) {
                $validator->errors()->add('amount', "Cannot exceed {$refundable} EGP for this order.");
            }

        } else {
            $validator->errors()->add('order_id', 'Please select a specific order or payment to refund from.');
            return;
        }
    });
}
}
