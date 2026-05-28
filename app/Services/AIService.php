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

    public function generateDescription(string $productName, float $price): array
{
    $systemPrompt = 'You are a retail copywriter. Respond with valid JSON only. No explanation, no markdown, no code blocks. Use exactly this structure: {"ar": "Arabic description", "en": "English description"}';

    $userMessage = "Write a 1-2 sentence product description for:
Product: {$productName}
Price: {$price} EGP

JSON only: {\"ar\": \"...\", \"en\": \"...\"}";

    $raw = $this->generate($systemPrompt, $userMessage, 300);

    $cleaned = preg_replace('/^```json\s*|\s*```$/s', '', trim($raw));

    $decoded = json_decode($cleaned, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $ar = $decoded['ar'] ?? $decoded['AR'] ?? '';
        $en = $decoded['en'] ?? $decoded['EN'] ?? '';
        if ($ar || $en) {
            return ['ar' => $ar, 'en' => $en];
        }
    }

    if (preg_match('/AR:\s*(.+?)(?=\s*EN:)/s', $raw, $arMatch) &&
        preg_match('/EN:\s*(.+?)$/s', $raw, $enMatch)) {
        return [
            'ar' => trim($arMatch[1]),
            'en' => trim($enMatch[1]),
        ];
    }

    return ['ar' => '', 'en' => trim($raw)];
}

public function generateInsights(array $salesData, string $tenantName = 'صاحب المتجر'): array
{
    $systemPrompt = 'أنت محلل أعمال متخصص في تجارة أدوات البناء والأدوات اليدوية في مصر. حلل بيانات المبيعات الفعلية فقط. أجب بـ JSON فقط بدون أي نص إضافي أو markdown.';

    $summary = json_encode($salesData, JSON_UNESCAPED_UNICODE);

    $userMessage = "بيانات مبيعات متجر {$tenantName} خلال آخر 30 يوم:

{$summary}

أرجع JSON فقط بهذا الشكل بالضبط:
{
  \"opportunity\": {
    \"title\": \"عنوان قصير\",
    \"body\": \"تفسير مختصر بناءً على البيانات\"
  },
  \"urgent\": {
    \"title\": \"عنوان قصير\",
    \"body\": \"تفسير مختصر بناءً على البيانات\"
  },
  \"trend\": {
    \"title\": \"عنوان قصير\",
    \"body\": \"تفسير مختصر بناءً على البيانات\"
  }
}";

    $raw = $this->generate($systemPrompt, $userMessage, 600);

    $cleaned = preg_replace('/^```json\s*|\s*```$/s', '', trim($raw));

    $decoded = json_decode($cleaned, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['opportunity'], $decoded['urgent'], $decoded['trend'])) {
        return $decoded;
    }

    // Fallback
    return [
        'opportunity' => ['title' => 'تحليل المبيعات', 'body' => $raw],
        'urgent'      => ['title' => '', 'body' => ''],
        'trend'       => ['title' => '', 'body' => ''],
    ];
}
}
