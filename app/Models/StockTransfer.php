<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use ScopedToTenant;

    const TYPE_MANUAL        = 'manual';
    const TYPE_REPLENISHMENT = 'replenishment';

    const STATUS_PENDING   = 'PENDING';
    const STATUS_APPROVED  = 'APPROVED';
    const STATUS_REJECTED  = 'REJECTED';
    const STATUS_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'type',
        'status',
        'order_id',
        'created_by',
        'notes',
        'batch_id',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
