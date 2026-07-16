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

    public function scopeWhereUnpaid($query)
    {
        return $query->whereRaw('total > (
            SELECT COALESCE(SUM(amount), 0)
            FROM supplier_payments
            WHERE supplier_payments.purchase_order_id = purchase_orders.id
        )');
    }

    /**
     * How much has been paid toward this purchase order. Supplier payments have
     * no refund concept (unlike customer Payments), so this is a plain sum.
     */
    public function settledAmount(): float
    {
        return round($this->supplierPayments->sum('amount'), 2);
    }

    public function isSettled(): bool
    {
        return $this->settledAmount() >= $this->total;
    }

}
