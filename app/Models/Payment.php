<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory, ScopedToTenant;
    protected $fillable = [
        'tenant_id',
        'order_id',
        'customer_id',
        'amount',
        'method',
        'paid_at',
        'is_auto_reversible',
        'counts_as_cash',
        'payment_reference',
    ];

    protected $casts = [
        'is_auto_reversible' => 'boolean',
        'counts_as_cash'     => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeCashOnly($query)
    {
        return $query->where('counts_as_cash', true);
    }

    public function scopeCreditOnly($query)
    {
        return $query->where('is_auto_reversible', true);
    }
}
