<?php

namespace DevPilot;

use DevPilot\Core\GitManager;
use DevPilot\Core\DbSnapshot;
use DevPilot\Core\LogParser;
use DevPilot\AI\Client as AIClient;
use DevPilot\AI\Context;
use DevPilot\AI\DiffApplier;

class Router 
{
    private array $config;

    public function __construct(array $config) 
    {
        $this->config = $config;
    }

    public function handleRequest(): void 
    {
        header('Content-Type: application/json; charset=utf-8');
        
        $action = $_GET['action'] ?? '';
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        // Вспомогательная функция для получения имени проекта из любого источника (JSON или GET/POST)
        $projectName = $input['project'] ?? $_REQUEST['project'] ?? array_key_first($this->config['projects'] ?? []);
        $projectCfg = $this->config['projects'][$projectName] ?? null;

        try {
            switch ($action) {
                case 'get_projects':
                    echo json_encode(['status' => 'ok', 'projects' => array_keys($this->config['projects'] ?? [])]);
                    break;

                case 'analyze':
                    if (!$projectCfg) {
                        throw new \InvalidArgumentException("Project '{$projectName}' not found in config.json configuration.");
                    }

                    // 1. Парсим логи за последние N секунд с фильтрацией по имени проекта
                    $logWindow = $this->config['log_window_seconds'] ?? 10;
                    $logParser = new LogParser();
                    $logs = $logParser->parseRecentLogs($projectCfg['log_path'], $logWindow, $projectName);

                    // 1.1. Делаем HTTP-зонд браузера по URL из конфигурации (если задан)
                    $browserProbeResult = [];
                    if (!empty($projectCfg['url'])) {
                        $browserProbeResult = \DevPilot\Core\BrowserProbe::fetch($projectCfg['url']);
                    }

                    // 2. Формируем контекст для ИИ
                    $systemPrompt = Context::getSystemInstruction();
                    $userPrompt = Context::buildUserPayload(
                        $logs, 
                        $browserProbeResult,
                        $input['active_file_path'] ?? '', 
                        $input['active_file_content'] ?? ''
                    );

                    // 3. Передаем полный массив конфигурации в AIClient
                    $ai = new AIClient($this->config);
                    $response = $ai->ask($systemPrompt, $userPrompt);

                    // 4. Отдаем результат и отладочные данные в едином чистом JSON
                    echo json_encode([
                        'status' => 'ok',
                        'debug_log_path' => $projectCfg['log_path'],
                        'debug_raw_logs' => $logs,
                        'debug_browser' => $browserProbeResult,
                        'debug_ai_prompt' => $userPrompt,
                        'result' => $response
                    ]);
                    break;

                case 'apply_fix':
                    if (!$projectCfg) {
                        throw new \InvalidArgumentException("Project '{$projectName}' not found.");
                    }

                    $projectPath = rtrim($projectCfg['path'], '/\\');
                    $gitPath = $projectCfg['git_path'] ?? 'git';

                    // 1. Дамп БД до применения патча
                    $dbStorage = $projectPath . '/.devpilot/snapshots';
                    $db = new DbSnapshot(
                        $projectCfg['db']['host'],
                        $projectCfg['db']['user'],
                        $projectCfg['db']['pass'],
                        $projectCfg['db']['name'],
                        $dbStorage
                    );

                    $git = new GitManager($projectPath, $gitPath);
                    $currentHash = $git->getCurrentHash();
                    if ($currentHash) {
                        $db->makeDump($currentHash);
                    }

                    // 2. Применение изменений к файлам
                    $applier = new DiffApplier($projectPath);
                    $appliedFiles = $applier->apply($input['changes'] ?? []);

                    // 3. Создание Git-коммита и дампа БД с хэшем коммита
                    $commitMsg = "DevPilot Fix: " . ($input['diagnosis_short'] ?? 'Auto fix');
                    $newHash = $git->createCommit($commitMsg);
                    if ($newHash) {
                        $db->makeDump($newHash);
                    }

                    echo json_encode([
                        'status' => 'ok', 
                        'applied' => $appliedFiles, 
                        'commit' => $newHash
                    ]);
                    break;

                case 'rollback':
                    if (!$projectCfg) {
                        throw new \InvalidArgumentException("Project '{$projectName}' not found.");
                    }

                    $targetHash = $input['commit_hash'] ?? '';
                    if (empty($targetHash)) {
                        throw new \InvalidArgumentException("Commit hash for rollback is not specified.");
                    }

                    $projectPath = rtrim($projectCfg['path'], '/\\');
                    $gitPath = $projectCfg['git_path'] ?? 'git';

                    // 1. Откат файлов через Git
                    $git = new GitManager($projectPath, $gitPath);
                    $git->rollbackTo($targetHash);

                    // 2. Восстановление состояния базы данных
                    $dbStorage = $projectPath . '/.devpilot/snapshots';
                    $db = new DbSnapshot(
                        $projectCfg['db']['host'],
                        $projectCfg['db']['user'],
                        $projectCfg['db']['pass'],
                        $projectCfg['db']['name'],
                        $dbStorage
                    );
                    $db->restoreDump($targetHash);

                    echo json_encode([
                        'status' => 'ok', 
                        'message' => "Project and database successfully rolled back to commit {$targetHash}"
                    ]);
                    break;

                default:
                    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error', 
                'message' => $e->getMessage()
            ]);
        }
    }
}