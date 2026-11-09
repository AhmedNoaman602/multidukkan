<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('counts_as_cash')->default(true)->after('is_auto_reversible');
        });

        DB::statement('UPDATE payments SET counts_as_cash = CASE WHEN is_auto_reversible = 1 THEN 0 ELSE 1 END');

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments
                MODIFY COLUMN counts_as_cash TINYINT(1) NOT NULL,
                MODIFY COLUMN is_auto_reversible TINYINT(1) NOT NULL,
                MODIFY COLUMN method ENUM('cash','bank_transfer','instapay','vodafone_cash','orange_cash','check','credit','return_offset') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments
                MODIFY COLUMN method ENUM('cash','bank_transfer','instapay','vodafone_cash','orange_cash','check','credit') NOT NULL,
                MODIFY COLUMN is_auto_reversible TINYINT(1) NOT NULL DEFAULT 0");
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('counts_as_cash');
        });
    }
};
