<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN type ENUM(
                'ORDER_CHARGE',
                'PAYMENT',
                'CREDIT_APPLY',
                'CREDIT_CONSUMED',
                'REVERSAL',
                'PURCHASE_CHARGE',
                'PURCHASE_REVERSAL',
                'SUPPLIER_PAYMENT',
                'REFUND'
            ) NOT NULL");
        }
        
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN type ENUM(
                'ORDER_CHARGE',
                'PAYMENT',
                'CREDIT_APPLY',
                'CREDIT_CONSUMED',
                'REVERSAL',
                'PURCHASE_CHARGE',
                'PURCHASE_REVERSAL',
                'SUPPLIER_PAYMENT'
            ) NOT NULL");
        }
    }
};