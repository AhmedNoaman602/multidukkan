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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->unique();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('customer_name')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->string('discount_type')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
