<?php

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InventoryPolicy
{

public function viewAny(User $user): bool
{
    return true;
}

public function view(User $user, Inventory $inventory): bool
{
    return true;
}

public function create(User $user): bool
{
    return in_array($user->role, ['tenant_admin', 'store_manager']);
}

public function update(User $user, Inventory $inventory): bool
{
    if ($user->role === 'tenant_admin') return true;
    return $user->store_id === $inventory->warehouse->store_id;
}

public function adjust(User $user, Inventory $inventory): bool
{
    if ($user->role === 'tenant_admin') return true;
    return $user->store_id === $inventory->warehouse->store_id;
}

}
