<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    /** @use HasFactory<\Database\Factories\LedgerFactory> */
    use HasFactory;
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'store_id',
        'type',
        'amount',
        'description',
        'reference_id',
        'reference_type',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    const TYPES = [
        'ORDER_CHARGE',  
        'PAYMENT',       
        'CREDIT_APPLY',  
        'REVERSAL',      
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
    public function reference()
    {
        return $this->morphTo();
    }
}
