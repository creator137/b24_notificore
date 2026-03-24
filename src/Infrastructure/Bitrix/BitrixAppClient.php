<?php

declare(strict_types=1);

namespace App\Infrastructure\Bitrix;

use RuntimeException;

final class BitrixAppClient
{
    public function __construct(
        private string $domain,
        private string $accessToken,
        private string $refreshToken = '',
        private string $clientId = '',
        private string $clientSecret = '',
        private bool $verifySsl = true,
        private string $oauthBaseUrl = 'https://oauth.bitrix.info/oauth/token'
    ) {
    }

    public function authState(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
        ];
    }

    public function call(string $method, array $fields = [], bool $allowRefresh = true): array
    {
        $response = $this->rawCall($method, $fields);

        if (!empty($response['error'])) {
            if ($allowRefresh && $this->shouldRefresh((string)$response['error'])) {
                $this->refreshTokens();
                $response = $this->rawCall($method, $fields);
            }

            if (!empty($response['error'])) {
                throw new RuntimeException(
                    'Bitrix24 error: ' . $response['error'] . ' / ' . ($response['error_description'] ?? '')
                );
            }
        }

        $result = $response['result'] ?? [];

        return is_array($result) ? $result : ['result' => $result];
    }

    public function ensureSmsSenderRegistered(string $code, string $name, string $handlerUrl): array
    {
        try {
            return $this->call('messageservice.sender.add', [
                'CODE' => $code,
                'TYPE' => 'SMS',
                'HANDLER' => $handlerUrl,
                'NAME' => $name,
            ]);
        } catch (\Throwable) {
            return $this->call('messageservice.sender.update', [
                'CODE' => $code,
                'HANDLER' => $handlerUrl,
                'NAME' => $name,
            ]);
        }
    }

    public function updateMessageStatus(string $code, string $messageId, string $status): array
    {
        return $this->call('messageservice.message.status.update', [
            'CODE' => $code,
            'message_id' => $messageId,
            'status' => $status,
        ]);
    }

    public function refreshTokens(): void
    {
        if ($this->refreshToken === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Bitrix24 token refresh is not configured');
        }

        $url = rtrim($this->oauthBaseUrl, '/') . '/';
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
            ]),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Bitrix24 OAuth cURL error: ' . $error);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid Bitrix24 OAuth response: ' . $response);
        }

        if ($httpCode >= 400 || !empty($data['error'])) {
            throw new RuntimeException(
                'Bitrix24 OAuth error: ' . ($data['error'] ?? $httpCode) . ' / ' . ($data['error_description'] ?? $response)
            );
        }

        $this->accessToken = (string)($data['access_token'] ?? '');
        $this->refreshToken = (string)($data['refresh_token'] ?? $this->refreshToken);

        if ($this->accessToken === '') {
            throw new RuntimeException('Bitrix24 OAuth did not return access_token');
        }
    }

    private function rawCall(string $method, array $fields = []): array
    {
        $domain = preg_replace('~^https?://~', '', trim($this->domain));
        $url = 'https://' . rtrim((string)$domain, '/') . '/rest/' . $method . '.json?auth=' . urlencode($this->accessToken);
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Bitrix24 cURL error: ' . $error);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid Bitrix24 JSON response: ' . $response);
        }

        if ($httpCode >= 400 && empty($data['error'])) {
            throw new RuntimeException('Bitrix24 HTTP error: ' . $httpCode . ' / ' . $response);
        }

        return $data;
    }

    private function shouldRefresh(string $errorCode): bool
    {
        return in_array($errorCode, [
            'expired_token',
            'invalid_token',
            'NO_AUTH_FOUND',
            'INVALID_CREDENTIALS',
        ], true);
    }
}
