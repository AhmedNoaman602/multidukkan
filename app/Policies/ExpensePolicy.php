<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    /**
     * Store staff never have access to expenses (fail closed).
     */
    public function viewAny(User $user): bool
    {
        return $user->isTenantAdmin() || $user->isStoreManager();
    }

    public function create(User $user): bool
    {
        return $user->isTenantAdmin() || $user->isStoreManager();
    }

    /**
     * Tenant check first (July security audit rule). A manager may only view
     * expenses in their own store; tenant-wide (NULL-store) expenses are
     * admin-only.
     */
    public function view(User $user, Expense $expense): bool
    {
        return $user->tenant_id === $expense->tenant_id
            && ($user->isTenantAdmin()
                || ($user->isStoreManager()
                    && $user->store_id
                    && $user->store_id === $expense->store_id));
    }

    /**
     * A manager may mutate an expense only when it is in their own store AND
     * was created by them. Both dimensions are enforced here (not just in the
     * index query) so authorization holds on any direct-access code path.
     */
    public function update(User $user, Expense $expense): bool
    {
        return $user->tenant_id === $expense->tenant_id
            && ($user->isTenantAdmin()
                || ($user->isStoreManager()
                    && $user->store_id
                    && $user->store_id === $expense->store_id
                    && $user->id === $expense->created_by));
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->update($user, $expense);
    }
}
