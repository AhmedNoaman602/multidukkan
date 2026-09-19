<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Order $order): bool
    {
        if ($user->tenant_id !== $order->tenant_id) return false;
        if ($user->role === 'tenant_admin') return true;
        return $user->store_id === $order->store_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
return in_array($user->role, ['tenant_admin', 'store_manager', 'store_staff']);    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Order $order): bool
    {
        if ($user->tenant_id !== $order->tenant_id) return false;
        if ($user->role === 'tenant_admin') return true;
        if (! in_array($user->role, ['store_manager', 'store_staff'])) return false;
        return $user->store_id === $order->store_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Order $order): bool
    {
        if ($user->tenant_id !== $order->tenant_id) return false;
        if ($user->role === 'tenant_admin') return true;
        if ($user->role === 'store_manager') return $user->store_id === $order->store_id;
        return false;
    }
}
