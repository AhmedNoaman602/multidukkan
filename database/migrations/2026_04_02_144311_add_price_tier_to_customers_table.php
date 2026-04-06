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
    Schema::table('customers', function (Blueprint $table) {
        $table->enum('price_tier', ['default', 'a', 'b', 'c', 'd', 'e'])
              ->default('default')
              ->after('address');
    });
}

public function down(): void
{
    Schema::table('customers', function (Blueprint $table) {
        $table->dropColumn('price_tier');
    });
}
};
