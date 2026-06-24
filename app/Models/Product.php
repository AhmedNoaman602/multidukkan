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
        'description',
        'description_ar',
        'description_en',
        'cost_price',
        'price',
        'price_a',
        'price_b',
        'price_c',
        'price_d',
        'price_e',
        'unit',
        'secondary_unit',
        'conversion_factor',
        'supplier_id',
        'opening_quantity',
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
    public function supplier()
{
    return $this->belongsTo(Supplier::class);
}
public function purchaseOrderItems()
{
    return $this->hasMany(PurchaseOrderItem::class);
}
}
