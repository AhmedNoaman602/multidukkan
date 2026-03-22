<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    const TYPE_SALE         = 'SALE';
    const TYPE_RETURN       = 'RETURN';
    const TYPE_TRANSFER_IN  = 'TRANSFER_IN';
    const TYPE_TRANSFER_OUT = 'TRANSFER_OUT';
    const TYPE_ADJUSTMENT   = 'ADJUSTMENT';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'quantity',
        'type',
        'reference_type',
        'reference_id',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

}
