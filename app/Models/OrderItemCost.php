<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

class OrderItemCost extends Model
{
    use ScopedToTenant;

    const SOURCE_AVERAGE_AT_SALE = 'average_at_sale';
    const SOURCE_RECONSTRUCTED   = 'reconstructed';

    protected $fillable = [
        'tenant_id',
        'order_item_id',
        'base_quantity',
        'unit_cost',
        'unit_cost_source',
        'returned_quantity',
    ];

    protected $casts = [
        'base_quantity'     => 'integer',
        'unit_cost'         => 'decimal:4',
        'returned_quantity' => 'integer',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
