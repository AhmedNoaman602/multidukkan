<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        if ($user->tenant_id !== $stockTransfer->tenant_id) return false;
        if ($user->role === 'tenant_admin') return true;
        return $user->store_id === $stockTransfer->store_id;
    }

    // Manual transfers complete immediately, so only roles that may approve one can create it.
    public function create(User $user): bool
    {
        return in_array($user->role, ['tenant_admin', 'store_manager']);
    }

    public function createFrom(User $user, Warehouse $from): bool
    {
        if ($user->tenant_id !== $from->tenant_id) return false;
        if ($user->role === 'tenant_admin') return true;
        if ($user->role === 'store_manager') return $user->store_id === $from->store_id;
        return false;
    }
}
