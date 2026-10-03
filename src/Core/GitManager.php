<?php

namespace DevPilot\Core;

class GitManager 
{
    private string $projectPath;
    private string $gitBinary;

    public function __construct(string $projectPath, string $gitBinary = 'git') 
    {
        $this->projectPath = rtrim($projectPath, '/\\');
        $this->gitBinary = !empty($gitBinary) ? $gitBinary : 'git';
    }

    /**
     * Выполнение команды Git через proc_open для высокой надежности и безопасности
     */
    private function runGit(array $args): array 
    {
        // Оборачиваем путь к бинарнику в кавычки на случай пробелов в путях Windows
        $gitBinary = '"' . trim($this->gitBinary, '"') . '"';
        
        $command = $gitBinary . ' ' . implode(' ', array_map('escapeshellarg', $args));
        
        $descriptors = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectPath);

        if (!is_resource($process)) {
            throw new \RuntimeException("Не удалось запустить процесс Git.");
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        file_put_contents(__DIR__ . '/git_debug.log', date('Y-m-d H:i:s') . " | Cmd: {$command} | Code: {$exitCode} | Out: {$stdout} | Err: {$stderr}\n", FILE_APPEND);

        return [
            'code'   => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr)
        ];
    }

    /**
     * Создание атомарного снимка (коммита)
     */
    public function createCommit(string $message): string 
    {
        $this->runGit(['add', '-A']);
        $result = $this->runGit(['commit', '-m', $message]);
        
        $outputCheck = $result['stdout'] . "\n" . $result['stderr'];
        
        if ($result['code'] !== 0 && !str_contains($outputCheck, 'nothing to commit')) {
            throw new \RuntimeException("Ошибка при создании коммита: " . ($result['stderr'] ?: $result['stdout']));
        }

        return $this->getCurrentHash() ?: '';
    }

    /**
     * Полный откат проекта к указанному коммиту (Машина времени)
     */
    public function rollbackTo(string $commitHash): bool 
    {
        $result = $this->runGit(['reset', '--hard', $commitHash]);
        return $result['code'] === 0;
    }

    /**
     * Получение текущего хеша коммита
     */
    public function getCurrentHash(): string 
    {
        $result = $this->runGit(['rev-parse', 'HEAD']);
        return $result['stdout'];
    }

    /**
     * Получение Unified Diff для отправки в UI / AI
     */
    public function getDiff(): string 
    {
        $result = $this->runGit(['diff']);
        return $result['stdout'];
    }
}