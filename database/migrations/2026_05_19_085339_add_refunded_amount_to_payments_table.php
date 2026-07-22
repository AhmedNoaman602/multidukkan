<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('payments', function (Blueprint $table) {
        $table->decimal('refunded_amount', 10, 2)->default(0)->after('amount');
    });

    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_refunded_amount CHECK (refunded_amount <= amount)');
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        DB::statement('ALTER TABLE payments DROP CHECK chk_payments_refunded_amount');
    }

    Schema::table('payments', function (Blueprint $table) {
        $table->dropColumn('refunded_amount');
    });
}
};
