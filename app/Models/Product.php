<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;
    protected $fillable = [
        'tenant_id',
        'name',
        'sku',
        'price',
        'price_a',
        'price_b',
        'price_c',
        'price_d',
        'price_e',
        'unit',
        'secondary_unit',
        'conversion_factor',
    ];

    protected $attributes = [
        'unit' => 'pcs',
    ];
    
    protected $casts = [
        'price' => 'decimal:2',
        ];
    
protected static function booted(): void
{
    static::deleting(function (Product $product) {
        $product->inventories()->delete();
    });
}

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}
