<?php

namespace DevPilot\AI;

class DiffApplier 
{
    private string $projectRoot;

    public function __construct(string $projectRoot) 
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    }

    private function resolveFilePath(string $rawPath): string 
    {
        if (empty($rawPath)) {
            return '';
        }
        $normalizedPath = str_replace('\\', '/', $rawPath);
        
        if (file_exists($normalizedPath) && !is_dir($normalizedPath)) {
            return $normalizedPath;
        }

        $relativePath = ltrim($normalizedPath, '/');
        $directPath = $this->projectRoot . '/' . $relativePath;
        
        // Если файла нет, но это создание нового — возвращаем прямой путь
        return $directPath;
    }

    public function validateChanges(array $changes): bool 
    {
        foreach ($changes as $change) {
            $rawPath = $change['file_path'] ?? '';
            if (empty($rawPath)) {
                throw new \InvalidArgumentException("ИИ сформировал правку без указания пути к файлу (file_path).");
            }
        }
        return true;
    }

    public function apply(array $changes): array 
    {
        $this->validateChanges($changes);
        $appliedFiles = [];

        foreach ($changes as $change) {
            $rawPath = $change['file_path'] ?? '';
            $filePath = $this->resolveFilePath($rawPath);
            $action = $change['action'] ?? 'create'; // По умолчанию теперь полная перезапись/создание

            // Создаем директорию, если её нет
            $dir = dirname($filePath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Если модель прислала полную замену (или action=create/replace с полным новым кодом)
            $newCode = $change['new_code'] ?? '';
            
            // Записываем файл целиком
            file_put_contents($filePath, $newCode);
            $appliedFiles[] = $rawPath;
        }

        return $appliedFiles;
    }
}