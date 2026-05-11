<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    if (DB::getDriverName() === 'mysql') {
        DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN type 
            ENUM('ORDER_CHARGE', 'PAYMENT', 'REVERSAL', 'CREDIT_APPLY', 'CREDIT_CONSUMED', 'PURCHASE_CHARGE', 'PURCHASE_REVERSAL', 'SUPPLIER_PAYMENT') 
            NOT NULL");
    }
}

public function down(): void
{
    DB::statement("ALTER TABLE ledger_entries MODIFY COLUMN type ENUM('ORDER_CHARGE', 'PAYMENT', 'REVERSAL', 'CREDIT_APPLY') NOT NULL");
}
};
