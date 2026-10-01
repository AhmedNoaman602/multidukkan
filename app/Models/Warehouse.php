<?php

namespace App\Models;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\OrderItem;
use App\Models\PurchaseOrderItem;
use App\Models\Tenant;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory, ScopedToTenant;

    const TYPE_SHELF   = 'shelf';
    const TYPE_STORAGE = 'storage';

    protected $fillable = [
        'id',
        'tenant_id',
        'store_id',
        'name',
        'type',
        'address',
        'phone',
        'email',
    ];
    public function isShelf(): bool
    {
        return $this->type === self::TYPE_SHELF;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
    public function inventoryTransactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
