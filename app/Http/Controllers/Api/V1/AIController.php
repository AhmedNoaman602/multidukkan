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
}
