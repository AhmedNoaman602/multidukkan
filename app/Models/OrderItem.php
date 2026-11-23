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
        'unit_cost_at_sale',
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

    // Historical cost of the line: unit_cost_at_sale is per base unit (D33).
    // Null when the cost is unknown: never counted as zero (4b).
    public function costOfGoods(): ?float
    {
        if ($this->unit_cost_at_sale === null) {
            return null;
        }

        return (float) ($this->baseQuantity() * $this->unit_cost_at_sale);
    }
}
