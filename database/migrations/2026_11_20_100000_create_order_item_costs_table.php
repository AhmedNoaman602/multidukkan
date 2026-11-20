<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureEveryLineCanBeBackfilled();

        Schema::create('order_item_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('base_quantity');
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->string('unit_cost_source', 32)->nullable();
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamps();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE order_item_costs ADD CONSTRAINT chk_order_item_costs_base_quantity CHECK (base_quantity > 0)');
            DB::statement('ALTER TABLE order_item_costs ADD CONSTRAINT chk_order_item_costs_returned_quantity CHECK (returned_quantity <= base_quantity)');
            DB::statement('ALTER TABLE order_item_costs ADD CONSTRAINT chk_order_item_costs_unit_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)');
        }

        // Existing lines get one allocation each with an unknown cost: history is never invented (D27, D30).
        DB::statement('
            INSERT INTO order_item_costs
                (tenant_id, order_item_id, base_quantity, unit_cost, unit_cost_source, returned_quantity, created_at, updated_at)
            SELECT orders.tenant_id, order_items.id, order_items.quantity * order_items.conversion_factor,
                   NULL, NULL, 0, order_items.created_at, order_items.created_at
            FROM order_items
            JOIN orders ON orders.id = order_items.order_id
            ORDER BY order_items.id
        ');

        $lines = DB::table('order_items')->count();
        $allocations = DB::table('order_item_costs')->count();

        if ($lines !== $allocations) {
            throw new RuntimeException("Backfill created {$allocations} allocations for {$lines} order lines.");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_costs');
    }

    private function ensureEveryLineCanBeBackfilled(): void
    {
        $invalid = DB::table('order_items')
            ->leftJoin('orders', 'orders.id', '=', 'order_items.order_id')
            ->where(fn ($q) => $q
                ->whereNull('orders.id')
                ->orWhere('order_items.quantity', '<=', 0)
                ->orWhere('order_items.conversion_factor', '<=', 0))
            ->pluck('order_items.id');

        if ($invalid->isNotEmpty()) {
            throw new RuntimeException('Order lines without an order or with a non-positive quantity: '.$invalid->implode(', '));
        }
    }
};
