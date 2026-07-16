<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;
class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "updated" event.
     *
     * Deliberately does not log 'created'/'deleted' here — ledger_entries'
     * ORDER_CHARGE already owns the creation moment; logging both would show
     * the same event twice in the merged activity feed.
     */
    public function updated(Order $order): void
    {
        $changes = collect($order->getChanges())
            ->except('updated_at')
            ->mapWithKeys(fn ($newValue, $field) => [
                $field => [$order->getOriginal($field), $newValue]
            ])
            ->toArray();

        AuditLog::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

    public function updating(Order $order): void
    {
    }

 public function deleting(Order $order): void
{
    $hasUnrefundedPayments = $order->payments()
        ->cashOnly()
        ->whereRaw('amount > COALESCE(refunded_amount, 0)')
        ->exists();

    if ($hasUnrefundedPayments) {
        throw ValidationException::withMessages([
            'order' => 'Cannot delete an order with payments. Remove payments first.',
        ]);
    }
}

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        //
    }
}
