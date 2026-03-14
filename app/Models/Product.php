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
    public function ordersItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
