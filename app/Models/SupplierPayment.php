<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SupplierPayment extends Model
{
    use HasFactory, ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'supplier_id',
        'amount',
        'method',
        'paid_at',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

}
