<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Money keeps 4 decimals (W7): a box of 1000 for 48.00 costs 0.048 a piece. 12,4 keeps the same
// largest amount as 10,2. change() replaces the whole column, so nullable and default are restated.
return new class extends Migration
{
    public function up(): void
    {
        $this->resize(12, 4);
    }

    // Rolling back rounds every value to 2 decimals; anything finer is lost.
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
            $table->decimal('total', $total, $places)->change();
        });

        Schema::table('purchase_orders', function (Blueprint $table) use ($total, $places) {
            $table->decimal('total', $total, $places)->change();
        });

        Schema::table('orders', function (Blueprint $table) use ($total, $places) {
            $table->decimal('total', $total, $places)->default(0)->change();
            $table->decimal('discount', $total, $places)->default(0)->change();
            $table->decimal('manual_total', $total, $places)->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) use ($total, $places) {
            $table->decimal('amount', $total, $places)->change();
            $table->decimal('refunded_amount', $total, $places)->default(0)->change();
        });

        foreach (['ledger_entries', 'expenses', 'supplier_payments'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($total, $places) {
                $table->decimal('amount', $total, $places)->change();
            });
        }
    }
};
