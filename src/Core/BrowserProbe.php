<?php

namespace DevPilot\Core;

class BrowserProbe 
{
    public static function fetch(string $url, int $timeout = 5): array 
    {
        if (empty($url)) {
            return ['status' => 0, 'body' => 'URL not specified'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => 'DevPilot-Debug-Agent/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'status' => 0,
                'error' => $error,
                'body' => "Failed to connect to URL: {$url}. Error: {$error}"
            ];
        }

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Проверяем тип контента
        $isJson = str_contains($headers, 'application/json');

        if ($isJson) {
            // Если это JSON, пытаемся его красиво декодировать/очистить
            $decoded = json_decode($body, true);
            $cleanBody = $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $body;
        } else {
            // Если это HTML, вырезаем мусор (скрипты, стили, SVG), оставляя текст и ошибки
            $cleanBody = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $body);
            $cleanBody = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $cleanBody);
            $cleanBody = strip_tags($cleanBody, '<h1><h2><h3><h4><p><div><br><code><pre>');
            // Убираем лишние пустые строки и пробелы
            $cleanBody = trim(preg_replace("/[\r\n]+/", "\n", $cleanBody));
            // Обрезаем, если страница слишком огромная, чтобы не забивать токенами контекст
            if (mb_strlen($cleanBody) > 3000) {
                $cleanBody = mb_substr($cleanBody, 0, 3000) . "\n...[trimmed by DevPilot: page too large]";
            }
        }

        return [
            'status' => $httpCode,
            'is_json' => $isJson,
            'body' => $cleanBody
        ];
    }
}