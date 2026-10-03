<?php

namespace DevPilot\AI;

class Context 
{
    /**
     * Формирование системного промпта, задающего поведение ИИ
     */
    public static function getSystemInstruction(): string 
    {
        return <<<PROMPT
Ты — строго диагност и ассистент разработки DevPilot.
Твоя задача — проанализировать логи за последние секунды, сопоставить их с кодом и дать точный диагноз.

КРИТИЧЕСКИЕ ПРАВИЛА:
1. Всегда возвращай измененный файл ЦЕЛИКОМ (от начала до конца) в поле "new_code". Никогда не используй фрагменты, old_code или частичные патчи, чтобы избежать ошибок сопоставления.
2. Ответ ВОЗВРАЩАЙ СТРОГО В JSON следующей структуры:
{
  "diagnosis_short": "Краткий диагноз в одно предложение",
  "diagnosis_long": "Подробный описательный диагноз проблемы на русском языке",
  "changes": [
    {
      "file_path": "относительный/путь/к/файлу.php",
      "action": "create",
      "new_code": "полный текст исправленного файла"
    }
  ]
}
PROMPT;
    }

    /**
     * Сборка пользовательского запроса со стерильной выжимкой
     */
    public static function buildUserPayload(array $recentLogs, array $browserProbe = [], string $activeFilePath = '', string $activeFileContent = ''): string 
    {
        $logsFormatted = !empty($recentLogs) ? implode("\n", $recentLogs) : "Свежих ошибок в логах за последние N секунд не обнаружено.";

        $browserContext = "";
        if (!empty($browserProbe)) {
            $status = $browserProbe['status'] ?? 0;
            $body = $browserProbe['body'] ?? '';
            $browserContext = "\n\nОТВЕТ БРАУЗЕРА / HTTP (Статус: {$status}):\n{$body}";
        }

        $fileContext = "";
        if ($activeFilePath && $activeFileContent) {
            $fileContext = "\n\nАКТИВНЫЙ ФАЙЛ ({$activeFilePath}):\n```php\n{$activeFileContent}\n```";
        }

        return <<<USER_DATA
СОБЫТИЯ И ЛОГИ (За последние N секунд):
{$logsFormatted}
{$browserContext}
{$fileContext}

Проанализируй проблему, найди причину и предоставь исправленный файл целиком в поле new_code.
USER_DATA;
    }
}