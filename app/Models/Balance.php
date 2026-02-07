<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Balance extends Model
{
    protected $fillable = [
        'customer_id',
        'order_id',
        'type',
        'reference',
        'description',
        'amount',
        'running_balance',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function order() { 
        return $this->belongsTo(Order::class); 
    }
    // Scopes for filtering by type
    public function scopePayments($query)
    {
        return $query->where('type', 'payment');
    }

    public function scopeInvoices($query)
    {
        return $query->where('type', 'invoice');
    }

    public function scopeRefunds($query)
    {
        return $query->where('type', 'refund');
    }

    /**
     * Calculate aging summary for a collection of transactions
     * Returns amounts grouped by age buckets
     */
    // public static function calculateAging($transactions)
    // {
    //     $now = now();
    //     $aging = [
    //         'current' => 0,    // 0-30 days
    //         '30_60' => 0,      // 31-60 days
    //         '60_90' => 0,      // 61-90 days
    //         'over_90' => 0,    // Over 90 days
    //     ];

    //     foreach ($transactions->where('type', 'invoice') as $transaction) {
    //         $daysDiff = $now->diffInDays($transaction->created_at);
            
    //         if ($daysDiff <= 30) {
    //             $aging['current'] += $transaction->amount;
    //         } elseif ($daysDiff <= 60) {
    //             $aging['30_60'] += $transaction->amount;
    //         } elseif ($daysDiff <= 90) {
    //             $aging['60_90'] += $transaction->amount;
    //         } else {
    //             $aging['over_90'] += $transaction->amount;
    //         }
    //     }

    //     // Subtract payments from aging (oldest first principle)
    //     $totalPaid = $transactions->where('type', 'payment')->sum('amount') + 
    //                  $transactions->where('type', 'refund')->sum('amount');
        
    //     // Apply payments to oldest buckets first
    //     foreach (['over_90', '60_90', '30_60', 'current'] as $bucket) {
    //         if ($totalPaid <= 0) break;
            
    //         $reduction = min($aging[$bucket], $totalPaid);
    //         $aging[$bucket] -= $reduction;
    //         $totalPaid -= $reduction;
    //     }

    //     return $aging;
    // }

    /**
     * Generate a unique reference number
     */
    public static function generateReference($type)
    {
        $prefix = match($type) {
            'payment' => 'PAY',
            'invoice' => 'INV',
            'refund' => 'REF',
            default => 'TXN'
        };
        
        return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
}
