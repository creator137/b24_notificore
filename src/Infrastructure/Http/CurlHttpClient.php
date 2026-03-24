<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Support\Json;
use RuntimeException;

final class CurlHttpClient
{
    public function request(
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        string $format = 'json',
        bool $verifySsl = true,
    ): array {
        $method = strtoupper($method);
        $normalizedHeaders = $headers;
        $body = null;

        if ($method === 'GET') {
            if ($payload !== []) {
                $query = http_build_query($payload);
                $url .= (str_contains($url, '?') ? '&' : '?') . $query;
            }
        } elseif ($format === 'json') {
            $body = Json::encode($payload);

            if (!$this->hasHeader($normalizedHeaders, 'Content-Type')) {
                $normalizedHeaders[] = 'Content-Type: application/json';
            }
        } elseif ($format === 'form') {
            $body = http_build_query($payload);

            if (!$this->hasHeader($normalizedHeaders, 'Content-Type')) {
                $normalizedHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        } else {
            throw new RuntimeException('Unsupported request format: ' . $format);
        }

        if (!$this->hasHeader($normalizedHeaders, 'Accept')) {
            $normalizedHeaders[] = 'Accept: application/json';
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $normalizedHeaders,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP client cURL error: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        return [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'body_raw' => $response,
            'body_json' => is_array($decoded) ? $decoded : null,
        ];
    }

    public function get(
        string $url,
        array $query = [],
        array $headers = [],
        bool $verifySsl = true,
    ): array {
        return $this->request('GET', $url, $query, $headers, 'form', $verifySsl);
    }

    public function post(
        string $url,
        array $payload = [],
        array $headers = [],
        string $format = 'json',
        bool $verifySsl = true,
    ): array {
        return $this->request('POST', $url, $payload, $headers, $format, $verifySsl);
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return true;
            }
        }

        return false;
    }
}
