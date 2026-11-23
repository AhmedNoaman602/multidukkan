<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
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
        // Order creation is a two-step insert-then-fill flow: Order::create() writes the header,
        // then OrderService immediately does ->update(['total' => ...]) (and ->save() for a custom
        // order_date). Those in-creation writes fire this observer too, but they are part of the
        // creation moment — already owned by the ORDER_CHARGE ledger entry — not genuine edits.
        // wasRecentlyCreated stays true on that same instance, but is false on a fresh model
        // loaded for a real PATCH edit, so this cleanly skips only the phantom create-time writes.
        if ($order->wasRecentlyCreated) {
            return;
        }

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

    // Backstop for any delete that skips OrderService::cancelOrder: an invoice may only go once
    // its whole charge is reversed (REVERSAL + store credit given back = total).
    public function deleting(Order $order): void
    {
        $reversed = LedgerEntry::where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->whereIn('type', ['REVERSAL', 'CREDIT_APPLY'])
            ->sum('amount');

        if (round((float) $reversed, 2) < round((float) $order->total, 2)) {
            throw ValidationException::withMessages([
                'order' => __('messages.order_charge_not_reversed'),
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
