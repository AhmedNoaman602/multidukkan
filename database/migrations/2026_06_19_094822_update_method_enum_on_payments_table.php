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
    if (DB::connection()->getDriverName() === 'mysql') {
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank_transfer','instapay','vodafone_cash','orange_cash','check','credit') NOT NULL");
    }
}

public function down(): void
{
    if (DB::connection()->getDriverName() === 'mysql') {
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank_transfer','check','credit') NOT NULL");
    }
}
};
