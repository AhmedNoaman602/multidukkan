<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
class PurchaseOrder extends Model
{
    use HasFactory , SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'tenant_id',
        'supplier_id',
        'supplier_name_snapshot',
        'total',
        'notes',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

}
