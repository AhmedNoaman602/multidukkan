<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prices and costs keep 4 decimals (W7, ADR-011): a box of 1000 for 48.50 costs 0.0485 a piece.
// Totals, payments and the ledger stay decimal(10,2), so balances are always in piasters.
// 12,4 keeps the same largest amount as 10,2. change() replaces the whole column, so nullable
// is restated; none of these columns has a default.
return new class extends Migration
{
    public function up(): void
    {
        $this->resize(12, 4);
    }

    // Rolling back rounds every price and cost to 2 decimals; anything finer is lost.
    public function down(): void
    {
        $this->resize(10, 2);
    }

    private function resize(int $total, int $places): void
    {
        Schema::table('products', function (Blueprint $table) use ($total, $places) {
            $table->decimal('price', $total, $places)->change();
            $table->decimal('price_a', $total, $places)->nullable()->change();
            $table->decimal('price_b', $total, $places)->nullable()->change();
            $table->decimal('price_c', $total, $places)->nullable()->change();
            $table->decimal('price_d', $total, $places)->nullable()->change();
            $table->decimal('price_e', $total, $places)->nullable()->change();
            $table->decimal('cost_price', $total, $places)->nullable()->change();
        });

        Schema::table('supplier_products', function (Blueprint $table) use ($total, $places) {
            $table->decimal('cost_price', $total, $places)->nullable()->change();
            $table->decimal('last_purchase_price', $total, $places)->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) use ($total, $places) {
            $table->decimal('unit_price', $total, $places)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) use ($total, $places) {
            $table->decimal('unit_price', $total, $places)->change();
        });
    }
};
