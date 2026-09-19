<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Services\LedgerService;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Order;


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
        $storeId    = $this->user()->store_id;

        if ($this->payment_id_target) {
            $payment = Payment::find($this->payment_id_target);
            if (!$payment || (int) $payment->customer_id !== (int) $customerId) {
                $validator->errors()->add('payment_id_target', __('messages.payment_not_customers'));
                return;
            }

            if ($payment->is_auto_reversible) {
                $validator->errors()->add('payment_id_target', __('messages.credit_payment_not_refundable'));
                return;
            }

            if ($storeId !== null && (int) optional($payment->order)->store_id !== (int) $storeId) {
                $validator->errors()->add('payment_id_target', __('messages.payment_not_in_store'));
                return;
            }

            $refundable = $this->ledgerService->refundableForPayment($payment);
            if ($this->amount > $refundable) {
                $validator->errors()->add('amount', __('messages.refund_exceeds_payment', ['max' => $refundable]));
            }
            return;
        }

        if ($this->order_id) {
            $order = Order::find($this->order_id);
            if (!$order || (int) $order->tenant_id !== (int) $tenantId) {
                $validator->errors()->add('order_id', __('messages.order_not_tenants'));
                return;
            }

            if ($storeId !== null && (int) $order->store_id !== (int) $storeId) {
                $validator->errors()->add('order_id', __('messages.order_not_in_store'));
                return;
            }

            $refundable = $this->ledgerService->refundableForOrder($this->order_id);

            if ($refundable <= 0) {
                $validator->errors()->add('amount', __('messages.order_fully_refunded'));
                return;
            }

            if ($this->amount > $refundable) {
                $validator->errors()->add('amount', __('messages.refund_exceeds_order', ['max' => $refundable]));
            }

        } else {
            $validator->errors()->add('order_id', __('messages.refund_target_required'));
            return;
        }
    });
}
}
