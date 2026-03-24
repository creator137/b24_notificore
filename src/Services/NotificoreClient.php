<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\NotificationClientInterface;
use App\Infrastructure\Http\CurlHttpClient;
use RuntimeException;

final class NotificoreClient implements NotificationClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $login = '',
        private readonly string $password = '',
        private readonly string $projectId = '',
        private readonly string $apiKey = '',
        private readonly string $authMode = 'x_api_key',
        private readonly string $requestFormat = 'json',
        private readonly string $smsSendPath = '/rest/sms/create',
        private readonly string $emailSendPath = '/email/send',
        private readonly string $apiKeyHeader = 'X-API-KEY',
        private readonly bool $verifySsl = true,
        private readonly string $originator = '',
        private readonly string $validity = '',
        private readonly string $tariff = '',
        private readonly string $is2way = '0',
        private readonly string $balancePath = '/rest/common/balance',
        private readonly string $smsStatusPath = '/rest/sms/{id}',
        private readonly string $smsStatusReferencePath = '/rest/sms/reference/{reference}',
        private readonly string $statusCallbackUrl = '',
        private readonly ?CurlHttpClient $httpClient = null,
    ) {
    }

    public function sendSms(string $phone, string $message): array
    {
        $this->guardSmsConfig();

        $normalizedPhone = $this->normalizePhone($phone);
        if ($normalizedPhone === '') {
            throw new RuntimeException('Phone is empty after normalization');
        }

        $reference = $this->buildReference($normalizedPhone, $message);
        $payload = [
            'destination' => 'phone',
            'msisdn' => $normalizedPhone,
            'reference' => $reference,
            'originator' => $this->originator,
            'body' => $message,
        ];

        if ($this->projectId !== '') {
            $payload['project_id'] = $this->projectId;
        }

        if ($this->validity !== '') {
            $payload['validity'] = (int)$this->validity;
        }

        if ($this->tariff !== '') {
            $payload['tariff'] = (int)$this->tariff;
        }

        if ($this->isTruthy($this->is2way)) {
            $payload['2way'] = 1;
        }

        if ($this->statusCallbackUrl !== '') {
            $payload['callback_url'] = $this->statusCallbackUrl;
        }

        $response = $this->request('POST', $this->smsSendPath, $payload);
        $json = $response['body_json'] ?? [];

        return [
            'success' => $this->isSuccessfulResponse($response),
            'status' => $this->extractSendStatus($response),
            'provider_message_id' => (string)($json['id'] ?? ''),
            'provider_reference' => (string)($json['reference'] ?? $reference),
            'price' => (string)($json['price'] ?? ''),
            'currency' => (string)($json['currency'] ?? ''),
            'channel' => 'sms',
            'error_message' => $this->extractErrorMessage($response),
            'request_payload' => $payload,
            'response' => $response,
        ];
    }

    public function sendEmail(string $email, string $subject, string $message): array
    {
        throw new RuntimeException('Email Notificore API is not configured yet');
    }

    public function getBalance(): array
    {
        $this->guardApiConfig();

        $response = $this->request('GET', $this->balancePath);
        $json = $response['body_json'] ?? [];

        return [
            'success' => $this->isSuccessfulResponse($response),
            'balance' => (string)($json['balance'] ?? $json['amount'] ?? ''),
            'currency' => (string)($json['currency'] ?? 'RUB'),
            'error_message' => $this->extractErrorMessage($response),
            'response' => $response,
        ];
    }

    public function getSmsStatus(string $providerMessageId): array
    {
        $this->guardApiConfig();

        $path = str_replace('{id}', rawurlencode($providerMessageId), $this->smsStatusPath);
        $response = $this->request('GET', $path);
        $json = $response['body_json'] ?? [];

        return [
            'success' => $this->isSuccessfulResponse($response),
            'status' => strtolower((string)($json['status'] ?? 'unknown')),
            'provider_message_id' => (string)($json['id'] ?? $providerMessageId),
            'provider_reference' => (string)($json['reference'] ?? ''),
            'error_message' => $this->extractErrorMessage($response),
            'response' => $response,
        ];
    }

    public function getSmsStatusByReference(string $reference): array
    {
        $this->guardApiConfig();

        $path = str_replace('{reference}', rawurlencode($reference), $this->smsStatusReferencePath);
        $response = $this->request('GET', $path);
        $json = $response['body_json'] ?? [];

        return [
            'success' => $this->isSuccessfulResponse($response),
            'status' => strtolower((string)($json['status'] ?? 'unknown')),
            'provider_message_id' => (string)($json['id'] ?? ''),
            'provider_reference' => (string)($json['reference'] ?? $reference),
            'error_message' => $this->extractErrorMessage($response),
            'response' => $response,
        ];
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $client = $this->httpClient ?? new CurlHttpClient();

        return $client->request(
            method: $method,
            url: $url,
            payload: $payload,
            headers: $this->buildHeaders(),
            format: $this->requestFormat,
            verifySsl: $this->verifySsl,
        );
    }

    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type: text/json; charset=utf-8',
            'Accept: application/json',
        ];

        $authMode = strtolower($this->authMode);

        if (in_array($authMode, ['x_api_key', 'header', 'bearer'], true) && $this->apiKey !== '') {
            $headerName = $this->resolveApiKeyHeaderName();
            $headerValue = $this->apiKey;

            if ($headerName === 'Authorization' && $authMode === 'bearer' && !str_starts_with($headerValue, 'Bearer ')) {
                $headerValue = 'Bearer ' . $headerValue;
            }

            $headers[] = $headerName . ': ' . $headerValue;
        }

        if ($authMode === 'basic' && ($this->login !== '' || $this->password !== '')) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->login . ':' . $this->password);
        }

        return $headers;
    }

    private function guardSmsConfig(): void
    {
        $this->guardApiConfig();

        if ($this->originator === '') {
            throw new RuntimeException('Notificore originator is empty');
        }

        if (mb_strlen($this->originator) > 14) {
            throw new RuntimeException('Notificore originator must be 14 chars or less');
        }

        if ($this->validity !== '') {
            $validity = (int)$this->validity;
            if ($validity < 1 || $validity > 72) {
                throw new RuntimeException('Notificore validity must be from 1 to 72');
            }
        }

        if ($this->tariff !== '') {
            $tariff = (int)$this->tariff;
            if ($tariff < 0 || $tariff > 9) {
                throw new RuntimeException('Notificore tariff must be from 0 to 9');
            }
        }
    }

    private function guardApiConfig(): void
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('Notificore baseUrl is empty');
        }

        $authMode = strtolower($this->authMode);

        if (in_array($authMode, ['x_api_key', 'header', 'bearer'], true) && $this->apiKey === '') {
            throw new RuntimeException('Notificore apiKey is empty');
        }

        if ($authMode === 'basic' && ($this->login === '' || $this->password === '')) {
            throw new RuntimeException('Notificore basic auth requires login and password');
        }
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($normalized) === 11 && $normalized[0] === '8') {
            return '7' . substr($normalized, 1);
        }

        if (strlen($normalized) === 10) {
            return '7' . $normalized;
        }

        return $normalized;
    }

    private function buildReference(string $phone, string $message): string
    {
        return 'b24-' . date('YmdHis') . '-' . substr(hash('sha256', $phone . '|' . $message . '|' . microtime(true)), 0, 16);
    }

    private function isTruthy(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function isSuccessfulResponse(array $response): bool
    {
        $json = $response['body_json'] ?? [];
        $error = (string)($json['error'] ?? '0');

        return $response['http_code'] >= 200
            && $response['http_code'] < 300
            && ($error === '' || $error === '0');
    }

    private function extractSendStatus(array $response): string
    {
        if ($this->isSuccessfulResponse($response)) {
            return 'accepted';
        }

        $json = $response['body_json'] ?? [];

        return strtolower((string)($json['status'] ?? 'http_error'));
    }

    private function extractErrorMessage(array $response): string
    {
        $json = $response['body_json'] ?? [];

        return trim((string)(
            $json['errorDescription']
            ?? $json['error_description']
            ?? $json['message']
            ?? ''
        ));
    }

    private function resolveApiKeyHeaderName(): string
    {
        $header = trim($this->apiKeyHeader);

        if ($header === '' || strtolower($header) === 'authorization') {
            return 'X-API-KEY';
        }

        return $header;
    }
}
