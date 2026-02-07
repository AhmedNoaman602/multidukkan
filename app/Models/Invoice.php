<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Order;
use App\Models\Customer;

class Invoice extends Model
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'customer_name',
        'total',
        'payment_status',
        'payment_method',
        'payment_date',
        'invoice_date',
    ];
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
