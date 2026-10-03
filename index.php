<?php

/**
 * DevPilot — Local AI Debugger & Control Center
 * 
 * Единая точка входа: отдаёт Web UI или обрабатывает API-запросы.
 */

declare(strict_types=1);
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Fatal Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});
set_time_limit(300); // Увеличиваем таймаут выполнения до 3 минут
require_once __DIR__ . '/src/Core/GitManager.php';
require_once __DIR__ . '/src/Core/DbSnapshot.php';
require_once __DIR__ . '/src/Core/LogParser.php';
require_once __DIR__ . '/src/AI/Client.php';
require_once __DIR__ . '/src/AI/Context.php';
require_once __DIR__ . '/src/AI/DiffApplier.php';
require_once __DIR__ . '/src/Router.php';
require_once __DIR__ . '/src/Core/BrowserProbe.php';

$configFile = __DIR__ . '/config.json';

if (!file_exists($configFile)) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Конфигурационный файл config.json не найден. Создайте его на основе config.example.json.']);
        exit;
    }
    
    // Если зашли через браузер без config.json — показываем заглушку с подсказкой
    echo "<h1>DevPilot — Требуется настройка</h1>";
    echo "<p>Файл <code>config.json</code> не найден. Скопируйте <code>config.example.json</code> в <code>config.json</code> и укажите ваши ключи API и пути к проектам Open Server.</p>";
    exit;
}

$config = json_decode(file_get_contents($configFile), true);

// Если в URL есть action — работаем как REST API, иначе отдаем HTML UI
if (isset($_GET['action'])) {
    $router = new \DevPilot\Router($config);
    $router->handleRequest();
    exit;
}

// Отдача фронтенд-интерфейса
require_once __DIR__ . '/client/index.html';