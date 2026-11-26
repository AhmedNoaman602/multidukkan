<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use App\Support\LocalDateRange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory , SoftDeletes, ScopedToTenant;
    protected $fillable = [
        'invoice_number',
        'tenant_id',
        'store_id',
        'customer_id',
        'customer_name_snapshot',
        'created_by',
        'notes',
        'discount',
        'manual_total',
        'total',
        'order_date'
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

    /**
     * Has the customer's obligation been fully satisfied? Counts every payment
     * that reduces debt, including store-credit (is_auto_reversible) payments.
     */
    public function settledAmount(): float
    {
        return round($this->payments->sum(fn ($p) => $p->amount - ($p->refunded_amount ?? 0)), 2);
    }

    public function isSettled(): bool
    {
        return $this->settledAmount() >= $this->total;
    }

    /**
     * How much real cash/card/transfer money has actually come in — excludes
     * non-cash payments (store credit, return offsets).
     */
    public function cashReceived(): float
    {
        return round($this->payments->where('counts_as_cash', true)
            ->sum(fn ($p) => $p->amount - ($p->refunded_amount ?? 0)), 2);
    }

    public function createdToday(): bool
    {
        $tz = LocalDateRange::businessTimezone();

        return $this->created_at !== null
            && $this->created_at->copy()->setTimezone($tz)->toDateString() === LocalDateRange::today($tz)->toDateString();
    }

    public function unknownCostLines(): int
    {
        return $this->items->filter(fn ($i) => $i->costOfGoods() === null)->count();
    }

    // Historical line costs (order_items.unit_cost_at_sale), never today's product cost (D33).
    // Null when any line's cost is unknown: an unknown cost is never counted as zero.
    public function costOfGoods(): ?float
    {
        if ($this->unknownCostLines() > 0) {
            return null;
        }

        return (float) $this->items->sum(fn ($i) => $i->costOfGoods());
    }
}
