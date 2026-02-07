<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Balance;
use App\Models\Order;
class Customer extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'total_orders',
        'total_spent',
        'price_tier',
        'balance',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    public function balances()
    {
        return $this->hasMany(Balance::class);
    }

    public function getTotalInvoicedAttribute()
    {
        return $this->balances->where('type', 'invoice')->sum('amount');
    }

    public function getTotalPaidAttribute()
    {
        return $this->balances->where('type', 'payment')->sum('amount') + 
               $this->balances->where('type', 'refund')->sum('amount');
    }

    public function getOutstandingBalanceAttribute()
    {
        return $this->total_invoiced - $this->total_paid;
    }

    public function getLastPaymentAttribute()
    {
        $lastPayment = $this->balances->where('type', 'payment')->sortByDesc('created_at')->first();
        return $lastPayment ? $lastPayment->created_at->format('M d, Y') : 'N/A';
    }
}
