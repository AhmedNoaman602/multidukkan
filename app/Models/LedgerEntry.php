<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    /** @use HasFactory<\Database\Factories\LedgerFactory> */
    use HasFactory, ScopedToTenant;
    protected $fillable = [
    'tenant_id',
    'customer_id',
    'store_id',
    'user_id',
    'supplier_id',
    'entity_type',
    'entity_id',
    'direction',
    'type',
    'amount',
    'description',
    'reference_id',
    'reference_type',
];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Microsecond precision so same-second events (e.g. an order's ORDER_CHARGE ledger
    // entry and its grouped inventory row, written milliseconds apart in one request)
    // still sort correctly newest-first in the activity feed.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    const TYPES = [
    'ORDER_CHARGE',
    'PAYMENT',
    'CREDIT_APPLY',
    'CREDIT_CONSUMED',
    'REVERSAL',
    'PURCHASE_CHARGE',
    'PURCHASE_REVERSAL',
    'SUPPLIER_PAYMENT',
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
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    // public function reference()
    // {
    //     return $this->morphTo();
    // }
}
