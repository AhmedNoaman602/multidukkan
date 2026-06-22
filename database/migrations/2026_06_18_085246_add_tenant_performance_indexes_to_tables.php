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
        // Orders — Optimized for tenant-scoped history and customer filter lookups
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'orders_tenant_created_idx');
            $table->index(['tenant_id', 'customer_id'], 'orders_tenant_customer_idx');
        });

        // Ledger — Core multi-tenant balance calculations and chronological feeds
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(['tenant_id', 'customer_id'], 'ledger_tenant_customer_idx');
            $table->index(['tenant_id', 'created_at'], 'ledger_tenant_created_idx');
        });

        // Products — Fast name auto-suggest inside a specific tenant
        Schema::table('products', function (Blueprint $table) {
            $table->index(['tenant_id', 'name'], 'products_tenant_name_idx');
        });

        // Customers — Fast lookup by name or phone within a tenant
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['tenant_id', 'name'], 'customers_tenant_name_idx');
            $table->index(['tenant_id', 'phone'], 'customers_tenant_phone_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_tenant_created_idx');
            $table->dropIndex('orders_tenant_customer_idx');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex('ledger_tenant_customer_idx');
            $table->dropIndex('ledger_tenant_created_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_tenant_name_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_tenant_name_idx');
            $table->dropIndex('customers_tenant_phone_idx');
        });
    }
};