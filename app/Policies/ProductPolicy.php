<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
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
    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
{
    return $user->role === 'tenant_admin';
}

public function update(User $user, Product $product): bool
{
    return $user->role === 'tenant_admin';
}

public function delete(User $user, Product $product): bool
{
    return $user->role === 'tenant_admin';
}
}
