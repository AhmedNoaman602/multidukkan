<?php

namespace App\Observers;

use App\Models\Payment;
use LogicException;

class PaymentObserver
{
    public function creating(Payment $payment): void
    {
        foreach (['counts_as_cash', 'is_auto_reversible'] as $flag) {
            if (! array_key_exists($flag, $payment->getAttributes())) {
                throw new LogicException("Payment {$flag} must be set explicitly.");
            }
        }
    }
}
