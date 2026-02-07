<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Inventory;
use App\Models\Order;

class Product extends Model
{
    protected $fillable = [
        'name', 
        'sku', 
        'price',
        'tier_prices',
        'unit',
        'cost',
        'stock_quantity',
        'low_stock_alert',
        // 'status'
    ];
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function inventories()
    {
        return $this->belongsToMany(Inventory::class);
    }
}
