<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('orders', function (Blueprint $table) {
        if (config('database.default') === 'sqlite') {
            $table->date('order_date')->default(DB::raw('CURRENT_DATE'))->after('discount');
        } else {
            $table->date('order_date')->default(DB::raw('(DATE(created_at))'))->after('discount');
        }
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropColumn('order_date');
    });
}
};
