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
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('reference_id')->constrained()->nullOnDelete();
            $table->string('notes', 500)->nullable()->after('user_id');
            // Groups the per-line-item rows written by a single order/PO action (create, cancel, ...)
            // so the activity feed can show one event instead of one row per product.
            $table->uuid('batch_id')->nullable()->after('notes')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('notes');
            $table->dropColumn('batch_id');
        });
    }
};
