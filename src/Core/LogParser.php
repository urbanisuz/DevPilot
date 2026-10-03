<?php

namespace DevPilot\Core;

class LogParser 
{
    public function parseRecentLogs(string $logPath, int $seconds = 10, ?string $projectName = null): array 
    {
        // 1. Проверяем, существует ли файл
        if (!file_exists($logPath)) {
            return [
                "WARNING: Log file not found at path: {$logPath}. Check your config.json configuration."
            ];
        }

        // 2. Читаем файл безопасно
        $content = @file_get_contents($logPath);
        if ($content === false || empty(trim($content))) {
            return [
                "NOTICE: Log file is empty: {$logPath}"
            ];
        }

        // Split на строки
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        
        // Берем последние 100 строк для анализа
        $recentLines = array_slice($lines, -100);

        // Фильтруем по названию проекта, если оно передано
        if (!empty($projectName)) {
            $recentLines = array_filter($recentLines, function($line) use ($projectName) {
                // Оставляем только строки, содержащие имя проекта/домена в путях или тексте ошибки
                return stripos($line, $projectName) !== false;
            });
        }

        // Оставляем последние 50 отфильтрованных строк
        $recentLines = array_slice(array_values($recentLines), -50);

        if (empty($recentLines)) {
            return [
                "NOTICE: No log entries found for project '{$projectName}'."
            ];
        }

        // Отфильтровываем пустые строки
        return array_values(array_filter(array_map('trim', $recentLines)));
    }
}