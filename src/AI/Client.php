<?php

namespace DevPilot\AI;

class Client 
{
    private array $config;

    public function __construct(array|string $config, ?string $apiKey = null, ?string $model = null) 
    {
        if (is_array($config)) {
            $this->config = $config;
        } else {
            $this->config = [
                'provider' => $config,
                'gemini_api_key' => $apiKey,
                'gemini_model' => $model ?? 'gemini-3.8-flash'
            ];
        }
    }

    public function analyze(string $prompt): array 
    {
        return $this->ask('', $prompt);
    }

    public function ask(string $systemPrompt, string $userPrompt): array 
    {
        $fullPrompt = trim($systemPrompt . "\n\n" . $userPrompt);

        $responseResult = null;
        
        try {
            $responseResult = $this->queryGemini($fullPrompt);
        } catch (\Throwable $e) {
            if (!empty($this->config['openrouter_api_key'])) {
                $responseResult = $this->queryOpenRouter($fullPrompt);
            } else {
                throw $e;
            }
        }

        $rawContent = $responseResult['content'] ?? '';
        
        if (is_array($rawContent)) {
            $rawContent = json_encode($rawContent, JSON_UNESCAPED_UNICODE);
        }

        $normalized = $this->normalizeResponse((string)$rawContent);
        $normalized['model'] = $responseResult['model'];

        return $normalized;
    }

    private function queryGemini(string $prompt): array 
    {
        $apiKey = $this->config['gemini_api_key'] ?? '';
        $model = $this->config['gemini_model'] ?? 'gemini-3.8-flash';

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException("Gemini API Error [{$httpCode}]: " . $response);
        }

        $data = json_decode($response, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return [
            'content' => $content,
            'model' => "gemini: " . $model
        ];
    }

    private function queryOpenRouter(string $prompt): array 
    {
        $apiKey = $this->config['openrouter_api_key'] ?? '';
        $model = $this->config['openrouter_model'] ?? 'openrouter/free';

        // Если авто-роутер снова попытается подсунуть эту хрень, сразу ругаемся
        if (str_contains($model, 'content-safety')) {
            throw new \RuntimeException("OpenRouter selected a safety classifier instead of a coding model. Please specify a real model in config.json (e.g. mistralai/mistral-7b-instruct:free).");
        }

        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $systemInstruction = "You are a PHP debug expert. You must respond ONLY with a valid JSON object matching this exact structure, with no markdown formatting or extra text outside the JSON:\n" .
            "{\n" .
            "  \"diagnosis_short\": \"Short summary of the bug\",\n" .
            "  \"diagnosis_long\": \"Detailed explanation of the issue\",\n" .
            "  \"changes\": [\n" .
            "    {\n" .
            "      \"old_code\": \"code before fix\",\n" .
            "      \"new_code\": \"code after fix\"\n" .
            "    }\n" .
            "  ]\n" .
            "}";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemInstruction],
                ['role' => 'user', 'content' => "Analyze these PHP logs and return ONLY valid JSON matching the requested structure:\n\n" . $prompt]
            ],
            'temperature' => 0.1
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: http://localhost',
                'X-Title: DevPilot Local Debugger'
            ],
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException("OpenRouter API Error [{$httpCode}]: " . $response);
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $usedModel = $data['model'] ?? $model;

        // Дополнительная проверка на случай, если роутер вернул вердикт безопасности в самом контенте
        if (str_contains($content, 'User Safety:') || trim($content) === 'safe') {
            throw new \RuntimeException("OpenRouter returned safety classifier response instead of code analysis. Please change 'openrouter_model' in config.json.");
        }

        return [
            'content' => $content,
            'model' => $usedModel
        ];
    }

    private function normalizeResponse(string $rawContent): array 
    {
        if (str_contains($rawContent, 'User Safety:') || trim($rawContent) === 'safe') {
            return [
                'success' => false,
                'diagnosis_short' => 'Model Router Error',
                'diagnosis_long'  => 'OpenRouter accidentally assigned a safety classifier model instead of a language model. Please try again.',
                'changes'         => []
            ];
        }

        $content = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', $rawContent);
        $content = trim($content);

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return [
                'success' => true,
                'diagnosis_short' => 'Analysis Completed',
                'diagnosis_long' => $rawContent,
                'changes' => []
            ];
        }

        return [
            'success' => true,
            'diagnosis_short' => $data['diagnosis_short'] ?? $data['summary'] ?? $data['title'] ?? 'Code Error',
            'diagnosis_long'  => $data['diagnosis_long'] ?? $data['diagnosis'] ?? $data['description'] ?? $rawContent,
            'changes'         => $data['changes'] ?? $data['patch'] ?? $data['diff'] ?? []
        ];
    }
}