<?php

namespace App\Services;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Illuminate\Support\Facades\Log;

class AIService
{
    /**
     * Create a new class instance.
     */

    private Provider $provider;
    private string $model;
    public function __construct()
    {
        $this->provider = Provider::Groq;
        $this->model = 'llama-3.1-8b-instant';
    }

    public function generate(string $systemPrompt, string $userMessage, int $maxTokens = 500){
        try{
            $response = Prism::text()
                ->using($this->provider, $this->model)
                ->withSystemPrompt($systemPrompt)
                ->withMaxTokens($maxTokens)
                ->withMessages([
                    new UserMessage($userMessage),
                ])
                ->generate();
            return $response->text;
        } catch (\Exception $e) {
            Log::error('AI Service Error: ' . $e->getMessage());
            return '';
        }
    }

    public function generateDescription(string $productName, string $unit, float $price) {
        $systemPrompt = "You are a professional retail copywriter for an Egyptian hardware and construction tools shop. Write product descriptions in both Arabic and English. Keep them concise, benefit-focused, and professional. Always return exactly this format:

            AR: [Arabic description here]
            EN: [English description here]";
            
                $userMessage = "Product name: {$productName}
            Unit: {$unit}
            Price: {$price} EGP
            
            Write a 1-2 sentence product description in both Arabic and English.";
            
            return $this->generate($systemPrompt, $userMessage, 300);

    }
}
