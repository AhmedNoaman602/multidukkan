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
        Schema::create('ledger_entries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->unsignedBigInteger('supplier_id')->nullable();
    $table->string('entity_type')->nullable();
    $table->unsignedBigInteger('entity_id')->nullable();
    $table->enum('direction', ['debit', 'credit'])->nullable();
    $table->enum('type', [
        'ORDER_CHARGE',
        'PAYMENT',
        'CREDIT_APPLY',
        'CREDIT_CONSUMED',
        'REVERSAL',
        'PURCHASE_CHARGE',
        'PURCHASE_REVERSAL',
        'SUPPLIER_PAYMENT',
    ]);
    $table->decimal('amount', 10, 2);
    $table->string('description')->nullable();
    $table->string('reference_id')->nullable();
    $table->string('reference_type')->nullable();
    $table->datetimes(6);
});

}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
