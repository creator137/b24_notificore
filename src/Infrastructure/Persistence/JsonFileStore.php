<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use RuntimeException;

final class JsonFileStore
{
    public function __construct(
        private readonly string $baseDir
    ) {}

    public function read(string $fileName): array
    {
        $path = $this->path($fileName);

        if (!file_exists($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $data;
    }

    public function write(string $fileName, array $data): void
    {
        $path = $this->path($fileName);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Cannot encode JSON for {$path}");
        }

        file_put_contents($path, $json);
    }

    private function path(string $fileName): string
    {
        return rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    }
}
