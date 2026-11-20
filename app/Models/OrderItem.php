<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory;
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'warehouse_id',
        'unit_type',
        'conversion_factor',
        'unit_name',
    ];

    protected $casts = [
        'conversion_factor' => 'integer',
    ];

    public function baseQuantity(): int
    {
        return (int) $this->quantity * $this->conversion_factor;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function costs()
    {
        return $this->hasMany(OrderItemCost::class);
    }

    // Saved sale cost of the line, from its allocations (already in base units).
    // Null when any allocation's cost is unknown, or the line has none: never counted as zero.
    public function costOfGoods(): ?float
    {
        if ($this->costs->isEmpty() || $this->costs->contains(fn ($c) => $c->unit_cost === null)) {
            return null;
        }

        return (float) $this->costs->sum(fn ($c) => $c->base_quantity * $c->unit_cost);
    }
}
