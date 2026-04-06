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
    ];

    protected $attributes = [
        'unit' => 'pcs',
    ];
    
    protected $casts = [
        'price' => 'decimal:2',
        ];

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
