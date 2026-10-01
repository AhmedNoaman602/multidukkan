<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class StoreService
{
    public function createStore(array $data, int $tenantId): Store
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $store = Store::create([
                'tenant_id' => $tenantId,
                'name'      => $data['name'],
                'address'   => $data['address'] ?? null,
                'phone'     => $data['phone'] ?? null,
            ]);

            Warehouse::create([
                'tenant_id' => $tenantId,
                'store_id'  => $store->id,
                'name'      => __('messages.default_shelf_name'),
                'type'      => Warehouse::TYPE_SHELF,
                'address'   => $store->address,
            ]);

            return $store->load('shelf');
        });
    }
}
