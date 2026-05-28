<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AIService;
use Illuminate\Http\JsonResponse;

class AIController extends Controller
{
    public function __construct(protected AIService $ai) {}

    public function test(): JsonResponse
    {
        $response = $this->ai->generate(
            systemPrompt: 'You are a helpful assistant.',
            userMessage: 'Say hello in Arabic and English. Keep it short.',
        );

        return response()->json([
            'message' => $response,
        ]);
    }

    public function describeProduct(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name'  => 'required|string',
        'price' => 'required|numeric|min:0',
    ]);

    $description = $this->ai->generateDescription(
        productName: $validated['name'],
        price:       (float) $validated['price'],
    );

    if (empty($description['ar']) && empty($description['en'])) {
        return response()->json(['message' => 'Failed to generate description'], 500);
    }

    return response()->json([
        'ar' => $description['ar'],
        'en' => $description['en'],
    ]);

}

public function insights(): JsonResponse
{
    $user = auth()->user();

    // Get last 30 days of orders for this tenant
    $orders = \App\Models\Order::where('tenant_id', $user->tenant_id)
        ->where('created_at', '>=', now()->subDays(30))
        ->with('items')
        ->get();

    if ($orders->isEmpty()) {
        return response()->json([
            'opportunity' => ['title' => 'لا توجد بيانات', 'body' => 'لا توجد بيانات مبيعات كافية في آخر 30 يوم.'],
            'urgent'      => ['title' => '', 'body' => ''],
            'trend'       => ['title' => '', 'body' => ''],
        ]);
}

    // Build sales summary
    $productSales = [];
    foreach ($orders as $order) {
        foreach ($order->items as $item) {
            $name = $item->product_name;
            if (!isset($productSales[$name])) {
                $productSales[$name] = [
                    'product'       => $name,
                    'total_quantity' => 0,
                    'total_revenue'  => 0,
                    'order_count'    => 0,
                ];
            }
            $productSales[$name]['total_quantity'] += $item->quantity;
            $productSales[$name]['total_revenue']  += $item->unit_price * $item->quantity;
            $productSales[$name]['order_count']    += 1;
        }
    }

    $salesData = [
        'period'         => 'آخر 30 يوم',
        'total_orders'   => $orders->count(),
        'total_revenue'  => round($orders->sum('total'), 2),
        'products'       => array_values($productSales),
    ];

    $insights = $this->ai->generateInsights($salesData, $user->tenant->name ?? 'صاحب المتجر');

return response()->json($insights);
}
}
