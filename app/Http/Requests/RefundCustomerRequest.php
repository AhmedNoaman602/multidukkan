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
        'amount'  => ['required', 'numeric', 'min:0.01'],
        'method'  => ['required', 'in:cash,bank_transfer,check'],
        'notes'   => ['nullable', 'string', 'max:500'],
        'order_id' => ['nullable', 'integer', 'exists:orders,id'],
    ];
}

public function withValidator($validator): void
{
    $validator->after(function ($validator) {
        $customer   = $this->route('customer');
        $tenantId   = $customer->tenant_id;
        $customerId = $customer->id;

        if ($this->order_id) {
            $totalPaid     = Payment::where('order_id', $this->order_id)->sum('amount');
            $totalRefunded = Payment::where('order_id', $this->order_id)->sum('refunded_amount');
            $refundable    = round($totalPaid - $totalRefunded, 2);

            if ($refundable <= 0) {
                $validator->errors()->add('amount', 'This order has already been fully refunded.');
                return;
            }

            if ($this->amount > $refundable) {
                $validator->errors()->add('amount', "Cannot exceed {$refundable} EGP for this order.");
            }

        } else {
            // General refund — only allowed when customer has credit balance
            $balance = $this->ledgerService->getBalance($tenantId, $customerId);

            if ($balance >= 0) {
                $validator->errors()->add('payment_id', 'Please select a specific payment to refund.');
                return;
            }

            $refundable = round(abs($balance), 2);

            if ($this->amount > $refundable) {
                $validator->errors()->add('amount', "Refund cannot exceed {$refundable} EGP.");
            }
        }
    });
}
}
