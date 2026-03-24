<?php

declare(strict_types=1);

namespace App\Support;

final class HttpRequest
{
    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function payload(): array
    {
        $payload = array_replace_recursive($_GET, $_POST);
        $raw = trim((string)file_get_contents('php://input'));

        if ($raw === '') {
            return $payload;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_replace_recursive($payload, $decoded);
        }

        parse_str($raw, $parsed);

        if (is_array($parsed) && $parsed !== []) {
            return array_replace_recursive($payload, $parsed);
        }

        return $payload;
    }
}
