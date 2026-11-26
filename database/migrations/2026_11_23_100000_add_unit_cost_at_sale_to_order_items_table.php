<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// D33: one historical cost per invoice line, per base unit. Existing lines stay NULL (unknown).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost_at_sale', 12, 4)->nullable()->after('unit_price');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_unit_cost_at_sale CHECK (unit_cost_at_sale IS NULL OR unit_cost_at_sale >= 0)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE order_items DROP CHECK chk_order_items_unit_cost_at_sale');
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost_at_sale');
        });
    }
};
