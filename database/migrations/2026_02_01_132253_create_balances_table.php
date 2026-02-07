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
        Schema::create('balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('cascade');
            $table->enum('type', ['payment', 'invoice', 'refund'])->default('invoice');
            $table->string('reference')->nullable(); // Invoice #, Receipt #, etc.
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('running_balance', 10, 2)->default(0); // Balance after this transaction
            $table->string('payment_method')->nullable(); // cash, bank_transfer, card, check
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
