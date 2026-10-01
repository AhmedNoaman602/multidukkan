<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'stock_transfer_id',
        'product_id',
        'quantity',
        'unit_type',
        'conversion_factor',
        'unit_name',
    ];

    protected $casts = [
        'quantity'          => 'integer',
        'conversion_factor' => 'integer',
    ];

    public function baseQuantity(): int
    {
        return $this->quantity * $this->conversion_factor;
    }

    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
