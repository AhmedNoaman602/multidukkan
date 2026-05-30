<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory , SoftDeletes;
    protected $fillable = [
        'invoice_number',
        'tenant_id',
        'store_id',
        'customer_id',
        'customer_name_snapshot',
        'created_by',
        'notes',
        'discount',
        'total'
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeWhereUnpaid($query)
{
    return $query->whereRaw('total > (
        SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0)
        FROM payments
        WHERE payments.order_id = orders.id
    )');
}
}
