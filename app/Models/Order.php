<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Customer;

class Order extends Model
{
    protected $fillable = [
        'customer_id',
        'customer_name',
        'order_id',
        'quantity',
        'total',
        'discount_amount',
        'discount_type',
        'payment_status',
    ];
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function products()
    {
        return $this->belongsToMany(Product::class , 'order_items')
                     ->withPivot('quantity', 'price', 'subtotal');
    }
    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }
    public function balances()
    {
        return $this->hasMany(Balance::class);
    }
    public function getTotalPaidAttribute()
    {
        return $this->balances()->where('type', 'payment')->sum('amount');
    }
    public function getRemainingBalanceAttribute()
    {
        return max(0, $this->total - $this->total_paid);
    }
}
