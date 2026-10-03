<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private Customer $customer;
    private array $sentSalesData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->sentSalesData = [];

        $this->mock(AIService::class, function ($mock) {
            $mock->shouldReceive('generateInsights')
                ->andReturnUsing(function (array $salesData) {
                    $this->sentSalesData = $salesData;

                    return [
                        'opportunity' => ['title' => 'فرصة', 'body' => 'نص'],
                        'urgent'      => ['title' => 'تنبيه', 'body' => 'نص'],
                        'trend'       => ['title' => 'اتجاه', 'body' => 'نص'],
                    ];
                });
        });
    }

    private function product(string $name, int $factor): Product
    {
        return Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'name'              => $name,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => $factor,
        ]);
    }

    // Each line is [quantity, conversionFactor, unitPrice]; a factor > 1 is a secondary-unit line.
    private function sell(Product $product, array ...$lines): void
    {
        foreach ($lines as [$quantity, $factor, $unitPrice]) {
            $order = Order::factory()->create([
                'tenant_id'   => $this->tenant->id,
                'store_id'    => $this->store->id,
                'customer_id' => $this->customer->id,
                'total'       => $unitPrice * $quantity,
                'order_date'  => now()->toDateString(),
            ]);
            OrderItem::factory()->create([
                'order_id'          => $order->id,
                'product_id'        => $product->id,
                'product_name'      => $product->name,
                'quantity'          => $quantity,
                'unit_price'        => $unitPrice,
                'unit_type'         => $factor > 1 ? 'secondary' : 'base',
                'conversion_factor' => $factor,
            ]);
        }
    }

    private function insightQuantities(): array
    {
        $this->actingAs($this->admin)
            ->getJson('/api/ai/insights')
            ->assertOk()
            ->assertJsonStructure([
                'opportunity' => ['title', 'body'],
                'urgent'      => ['title', 'body'],
                'trend'       => ['title', 'body'],
            ]);

        return collect($this->sentSalesData['products'])->pluck('total_quantity', 'product')->all();
    }

    public function test_a_piece_sale_counts_its_pieces(): void
    {
        $this->sell($this->product('مسمار', 12), [7, 1, 2]);

        $this->assertEquals(['مسمار' => 7], $this->insightQuantities());
    }

    public function test_a_box_sale_counts_its_base_units(): void
    {
        $this->sell($this->product('مسمار', 12), [3, 12, 24]); // 3 boxes

        $this->assertEquals(['مسمار' => 36], $this->insightQuantities()); // not 3
    }

    public function test_box_and_piece_lines_of_one_product_add_up_in_base_units(): void
    {
        $this->sell($this->product('مسمار', 12), [2, 12, 24], [5, 12, 24], [5, 1, 2]); // 2 + 5 boxes + 5 pcs

        $this->assertEquals(['مسمار' => 89], $this->insightQuantities()); // 84 + 5, not 12
    }

    public function test_products_with_different_factors_each_use_their_own_factor(): void
    {
        $this->sell($this->product('مسمار', 12), [2, 12, 24]);
        $this->sell($this->product('مفك', 6), [3, 6, 60]);

        $this->assertEquals(['مسمار' => 24, 'مفك' => 18], $this->insightQuantities());
    }

    public function test_quantities_match_units_sold_in_the_daily_report(): void
    {
        $this->sell($this->product('مسمار', 12), [3, 12, 24], [4, 1, 2]);
        $this->sell($this->product('مفك', 6), [2, 6, 60]);

        $reported = collect(
            $this->actingAs($this->admin)
                ->getJson('/api/reports/daily?from=' . now()->toDateString() . '&to=' . now()->addDay()->toDateString())
                ->assertOk()
                ->json('products_sold')
        )->pluck('units_sold', 'product_name')->all();

        $this->assertEquals($reported, $this->insightQuantities());
    }
}
