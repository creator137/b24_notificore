<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SendLogger
{
    public function __construct(
        private readonly string $logPath
    ) {
    }

    public function log(array $record): void
    {
        $dir = dirname($this->logPath);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create log directory: ' . $dir);
        }

        $normalized = $this->normalize($record);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $line = json_encode($normalized, $flags);

        if ($line === false) {
            throw new RuntimeException('Cannot encode log record to JSON: ' . json_last_error_msg());
        }

        file_put_contents($this->logPath, $line . PHP_EOL, FILE_APPEND);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$this->normalizeKey($key)] = $this->normalize($item);
            }

            return $result;
        }

        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        return $value;
    }

    private function normalizeKey(mixed $key): mixed
    {
        if (!is_string($key)) {
            return $key;
        }

        return $this->normalizeString($key);
    }

    private function normalizeString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1251, CP1251, ISO-8859-1');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return '[invalid-utf8]';
    }
}