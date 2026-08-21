<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\Order;

class OrderBelongsToCustomer implements ValidationRule
{
    public function __construct(
        private ?int $customerId
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // customer_id's own 'required' rule handles the missing case — nothing to compare against here.
        if ($this->customerId === null) {
            return;
        }

        $order = Order::find($value);

        if ($order && $order->customer_id !== $this->customerId) {
            $fail(__('messages.customer_order_mismatch'));
        }
    }
}
