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
        $table->string('secondary_unit')->nullable()->after('unit');
        $table->integer('conversion_factor')->nullable()->after('secondary_unit');
    });
}

public function down(): void
{
    Schema::table('products', function (Blueprint $table) {
        $table->dropColumn(['secondary_unit', 'conversion_factor']);
    });
}
};
