<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    use ScopedToTenant;

    const TYPE_SALE         = 'SALE';
    const TYPE_RETURN       = 'RETURN';
    const TYPE_TRANSFER_IN  = 'TRANSFER_IN';
    const TYPE_TRANSFER_OUT = 'TRANSFER_OUT';
    const TYPE_ADJUSTMENT_IN  = 'ADJUSTMENT_IN';
    const TYPE_ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';
    const TYPE_PURCHASE_IN  = 'PURCHASE_IN';
    const TYPE_PURCHASE_OUT = 'PURCHASE_OUT';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'quantity',
        'type',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
        'batch_id',
    ];

    // See LedgerEntry::$dateFormat — same reasoning.
    protected $dateFormat = 'Y-m-d H:i:s.u';

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
