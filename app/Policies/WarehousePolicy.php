<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\Response;

class WarehousePolicy
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
    public function view(User $user, Warehouse $warehouse): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
    return in_array($user->role, ['tenant_admin', 'store_manager']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Warehouse $warehouse): bool
    {
        if($user->role === 'tenant_admin') return true;
        if ($user->role === 'store_manager') return $user->store_id === $warehouse->store_id;
        return false;    
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Warehouse $warehouse): bool
    {
        if ($user->role === 'tenant_admin') return true;
        if ($user->role === 'store_manager') return $user->store_id === $warehouse->store_id;
        return false;  
    }
}
