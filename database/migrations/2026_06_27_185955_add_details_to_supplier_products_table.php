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
        Schema::table('supplier_products', function (Blueprint $table) {
          $table->decimal('cost_price' ,10 ,2)->nullable()->after('product_id');
          $table->decimal('last_purchase_price' ,10 ,2)->nullable();
          $table->date('last_purchased_at')->nullable();
          $table->boolean('is_preferred')->default(false);
          $table->string('notes')->nullable();
          $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
        $table->dropColumn(['cost_price', 'last_purchase_price', 'last_purchased_at', 'is_preferred', 'notes']);
        $table->dropTimestamps();

        });
    }
};
