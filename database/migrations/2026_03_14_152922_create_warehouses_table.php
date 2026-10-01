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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('storage');
            // Non-null only for the shelf, so the unique index allows at most one shelf per store.
            // Virtual, not stored: MySQL forbids a cascading FK on a stored generated column's base column.
            $table->unsignedBigInteger('shelf_store_id')->nullable()->virtualAs("CASE WHEN type = 'shelf' THEN store_id END");
            $table->unique('shelf_store_id');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->datetimes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
