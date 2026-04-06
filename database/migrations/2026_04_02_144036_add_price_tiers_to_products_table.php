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
    Schema::table('products', function (Blueprint $table) {
        $table->decimal('price_a', 10, 2)->nullable()->after('price');
        $table->decimal('price_b', 10, 2)->nullable()->after('price_a');
        $table->decimal('price_c', 10, 2)->nullable()->after('price_b');
        $table->decimal('price_d', 10, 2)->nullable()->after('price_c');
        $table->decimal('price_e', 10, 2)->nullable()->after('price_d');
    });
}

public function down(): void
{
    Schema::table('products', function (Blueprint $table) {
        $table->dropColumn(['price_a', 'price_b', 'price_c', 'price_d', 'price_e']);
    });
}
};
