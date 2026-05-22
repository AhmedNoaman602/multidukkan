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
        'unit'  => 'required|string',
        'price' => 'required|numeric|min:0',
    ]);

    $description = $this->ai->generateDescription(
        productName: $validated['name'],
        unit:        $validated['unit'],
        price:       (float) $validated['price'],
    );

    if (empty($description)) {
        return response()->json(['message' => 'Failed to generate description'], 500);
    }

    return response()->json(['description' => $description]);
}
}
