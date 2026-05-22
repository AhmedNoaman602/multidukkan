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
}
