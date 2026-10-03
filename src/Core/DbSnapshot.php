<?php

namespace DevPilot\Core;

class DbSnapshot 
{
    private string $host;
    private string $user;
    private string $password;
    private string $dbName;
    private string $storageDir;

    public function __construct(string $host, string $user, string $password, string $dbName, string $storageDir) 
    {
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->dbName = $dbName;
        $this->storageDir = rtrim($storageDir, '/\\');

        if (!file_exists($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Создание дампа структуры и данных БД
     */
    public function makeDump(string $snapshotId): string 
    {
        $filePath = $this->storageDir . "/snapshot_{$snapshotId}.sql";
        
        $cmd = sprintf(
            'mysqldump --host=%s --user=%s %s %s > %s',
            escapeshellarg($this->host),
            escapeshellarg($this->user),
            $this->password ? '--password=' . escapeshellarg($this->password) : '',
            escapeshellarg($this->dbName),
            escapeshellarg($filePath)
        );

        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \RuntimeException("Ошибка при дампе базы данных.");
        }

        return $filePath;
    }

    /**
     * Восстановление БД из снимка
     */
    public function restoreDump(string $snapshotId): bool 
    {
        $filePath = $this->storageDir . "/snapshot_{$snapshotId}.sql";

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Файл дампа не найден: {$filePath}");
        }

        $cmd = sprintf(
            'mysql --host=%s --user=%s %s %s < %s',
            escapeshellarg($this->host),
            escapeshellarg($this->user),
            $this->password ? '--password=' . escapeshellarg($this->password) : '',
            escapeshellarg($this->dbName),
            escapeshellarg($filePath)
        );

        exec($cmd, $output, $returnVar);

        return $returnVar === 0;
    }
}