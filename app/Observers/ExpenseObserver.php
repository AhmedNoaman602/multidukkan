<?php

namespace App\Observers;
use App\Models\Expense;
use App\Models\AuditLog;

class ExpenseObserver
{
    /**
     * Handle the Expense "created" event.
     */
    public function created(Expense $expense): void
    {
        AuditLog::create([
            'tenant_id' => $expense->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Expense::class,
            'auditable_id' => $expense->id,
            'action' => 'created',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Expense "updated" event.
     */
    public function updated(Expense $expense): void
    {
        $changes = collect($expense->getChanges())
            ->except('updated_at')
            ->mapWithKeys(fn ($newValue, $field) => [
                $field => [$expense->getOriginal($field), $newValue]
            ])
            ->toArray();

        AuditLog::create([
            'tenant_id' => $expense->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Expense::class,
            'auditable_id' => $expense->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

    public function deleted(Expense $expense): void
    {
        AuditLog::create([
            'tenant_id' => $expense->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Expense::class,
            'auditable_id' => $expense->id,
            'action' => 'deleted',
            'changes' => null,
        ]);
    }
}
