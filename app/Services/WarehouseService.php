<?php

namespace App\Services;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function deleteWarehouse(Warehouse $warehouse): void
    {
        DB::transaction(fn () => $warehouse->delete());
    }
}
