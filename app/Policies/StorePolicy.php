<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StorePolicy
{
 public function viewAny(User $user): bool
{
    return true;
}

public function view(User $user, Store $store): bool
{
    return true;
}

public function create(User $user): bool
{
    return $user->role === 'tenant_admin';
}

public function update(User $user, Store $store): bool
{
    return $user->role === 'tenant_admin';
}

public function delete(User $user, Store $store): bool
{
    return $user->role === 'tenant_admin';
}
}
