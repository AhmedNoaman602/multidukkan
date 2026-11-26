<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// CREDIT_PAYOUT: customer credit paid out in cash (a customer debit).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN type ENUM(
                'ORDER_CHARGE',
                'PAYMENT',
                'CREDIT_APPLY',
                'CREDIT_CONSUMED',
                'REVERSAL',
                'PURCHASE_CHARGE',
                'PURCHASE_REVERSAL',
                'SUPPLIER_PAYMENT',
                'REFUND',
                'SUPPLIER_PAYMENT_REVERSAL',
                'CREDIT_PAYOUT'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $payouts = DB::table('ledger_entries')->where('type', 'CREDIT_PAYOUT')->count();
        if ($payouts > 0) {
            throw new RuntimeException("{$payouts} CREDIT_PAYOUT ledger entries exist; removing the type would corrupt them.");
        }

        DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN type ENUM(
            'ORDER_CHARGE',
            'PAYMENT',
            'CREDIT_APPLY',
            'CREDIT_CONSUMED',
            'REVERSAL',
            'PURCHASE_CHARGE',
            'PURCHASE_REVERSAL',
            'SUPPLIER_PAYMENT',
            'REFUND',
            'SUPPLIER_PAYMENT_REVERSAL'
        ) NOT NULL");
    }
};
