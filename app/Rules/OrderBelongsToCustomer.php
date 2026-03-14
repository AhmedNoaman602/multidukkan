<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\Order;

class OrderBelongsToCustomer implements ValidationRule
{
    public function __construct(
        private int $customerId
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $order = Order::find($value);

        if ($order && $order->customer_id !== $this->customerId) {
            $fail('Customer does not match the order.');
        }
    }
}
