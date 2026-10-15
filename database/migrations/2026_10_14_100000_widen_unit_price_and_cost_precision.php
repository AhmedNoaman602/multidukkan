<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-unit prices and costs get 6 decimals: a box price is piece price × box size, and a
// carton of 1000 for 12.51 costs 0.01251 a piece. Transaction amounts stay at 2.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 14, 6)->change();
            $table->decimal('price_a', 14, 6)->nullable()->change();
            $table->decimal('price_b', 14, 6)->nullable()->change();
            $table->decimal('price_c', 14, 6)->nullable()->change();
            $table->decimal('price_d', 14, 6)->nullable()->change();
            $table->decimal('price_e', 14, 6)->nullable()->change();
            $table->decimal('cost_price', 14, 6)->nullable()->change();
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->decimal('cost_price', 14, 6)->nullable()->change();
            $table->decimal('last_purchase_price', 14, 6)->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 6)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 6)->change();
        });
    }

    // Rolling back rounds every value to 2 decimals; anything finer is lost.
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->change();
            $table->decimal('price_a', 10, 2)->nullable()->change();
            $table->decimal('price_b', 10, 2)->nullable()->change();
            $table->decimal('price_c', 10, 2)->nullable()->change();
            $table->decimal('price_d', 10, 2)->nullable()->change();
            $table->decimal('price_e', 10, 2)->nullable()->change();
            $table->decimal('cost_price', 10, 2)->nullable()->change();
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->nullable()->change();
            $table->decimal('last_purchase_price', 10, 2)->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->change();
        });
    }
};
