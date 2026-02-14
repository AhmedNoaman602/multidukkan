<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Balance;
use App\Models\Order;
use App\Models\LedgerEntry;
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
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class, 'account_id')->where('account_type', 'customer');
    }


  public function balance()
{
    return \App\Models\LedgerEntry::where('account_type', 'customer')
        ->where('account_id', $this->id)
        ->selectRaw("
            COALESCE(SUM(CASE 
                WHEN type = 'debit' THEN amount 
                WHEN type = 'credit' THEN -amount 
            END), 0) as balance
        ")
        ->value('balance');
}

    public function getTotalInvoicedAttribute()
    {
        return $this->ledgerEntries->where('type', 'debit')->sum('amount');
    }

    public function getTotalPaidAttribute()
    {
        return $this->ledgerEntries->where('type', 'credit')->sum('amount');
    }

    public function getOutstandingBalanceAttribute()
    {
        return $this->total_invoiced - $this->total_paid;
    }

    public function getLastPaymentAttribute()
    {
        $lastPayment = $this->ledgerEntries->where('type', 'credit')->sortByDesc('created_at')->first();
        return $lastPayment ? $lastPayment->created_at->format('M d, Y') : 'N/A';
    }
}
