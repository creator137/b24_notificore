<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\NotificationClientInterface;
use RuntimeException;

final class NotificationClientFactory
{
    public static function make(): NotificationClientInterface
    {
        return self::makeFromSettings([]);
    }

    public static function makeFromSettings(array $settings): NotificationClientInterface
    {
        $merged = array_replace(self::defaults(), $settings);
        $mode = self::resolveMode((string)($merged['mode'] ?? ''));

        return match ($mode) {
            'mock' => new MockNotificoreClient(),
            'real', 'notificore' => new NotificoreClient(
                baseUrl: (string)($merged['base_url'] ?? ''),
                login: (string)($merged['login'] ?? ''),
                password: (string)($merged['password'] ?? ''),
                projectId: (string)($merged['project_id'] ?? ''),
                apiKey: (string)($merged['api_key'] ?? ''),
                authMode: (string)($merged['auth_mode'] ?? 'x_api_key'),
                requestFormat: (string)($merged['request_format'] ?? 'json'),
                smsSendPath: (string)($merged['sms_send_path'] ?? '/rest/sms/create'),
                emailSendPath: (string)($merged['email_send_path'] ?? '/email/send'),
                apiKeyHeader: (string)($merged['api_key_header'] ?? 'X-API-KEY'),
                verifySsl: self::toBool($merged['verify_ssl'] ?? '1'),
                originator: (string)($merged['originator'] ?? ''),
                validity: (string)($merged['validity'] ?? ''),
                tariff: (string)($merged['tariff'] ?? ''),
                is2way: (string)($merged['is_2way'] ?? '0'),
                balancePath: (string)($merged['balance_path'] ?? '/rest/common/balance'),
                smsStatusPath: (string)($merged['sms_status_path'] ?? '/rest/sms/{id}'),
                smsStatusReferencePath: (string)($merged['sms_status_reference_path'] ?? '/rest/sms/reference/{reference}'),
                statusCallbackUrl: (string)($merged['status_callback_url'] ?? ''),
            ),
            default => throw new RuntimeException('Unsupported notificore mode: ' . $mode),
        };
    }

    public static function defaults(): array
    {
        return [
            'mode' => self::resolveMode((string)($_ENV['NOTIFICORE_MODE'] ?? '')),
            'base_url' => (string)($_ENV['NOTIFICORE_BASE_URL'] ?? 'https://api.notificore.ru'),
            'login' => (string)($_ENV['NOTIFICORE_LOGIN'] ?? ''),
            'password' => (string)($_ENV['NOTIFICORE_PASSWORD'] ?? ''),
            'project_id' => (string)($_ENV['NOTIFICORE_PROJECT_ID'] ?? ''),
            'api_key' => (string)($_ENV['NOTIFICORE_API_KEY'] ?? ''),
            'auth_mode' => (string)($_ENV['NOTIFICORE_AUTH_MODE'] ?? 'x_api_key'),
            'request_format' => (string)($_ENV['NOTIFICORE_REQUEST_FORMAT'] ?? 'json'),
            'sms_send_path' => (string)($_ENV['NOTIFICORE_SMS_SEND_PATH'] ?? '/rest/sms/create'),
            'email_send_path' => (string)($_ENV['NOTIFICORE_EMAIL_SEND_PATH'] ?? '/email/send'),
            'api_key_header' => (string)($_ENV['NOTIFICORE_API_KEY_HEADER'] ?? 'X-API-KEY'),
            'verify_ssl' => (string)($_ENV['NOTIFICORE_VERIFY_SSL'] ?? '1'),
            'originator' => (string)($_ENV['NOTIFICORE_ORIGINATOR'] ?? ''),
            'validity' => (string)($_ENV['NOTIFICORE_VALIDITY'] ?? ''),
            'tariff' => (string)($_ENV['NOTIFICORE_TARIFF'] ?? ''),
            'is_2way' => (string)($_ENV['NOTIFICORE_2WAY'] ?? '0'),
            'balance_path' => (string)($_ENV['NOTIFICORE_BALANCE_PATH'] ?? '/rest/common/balance'),
            'sms_status_path' => (string)($_ENV['NOTIFICORE_SMS_STATUS_PATH'] ?? '/rest/sms/{id}'),
            'sms_status_reference_path' => (string)($_ENV['NOTIFICORE_SMS_STATUS_REFERENCE_PATH'] ?? '/rest/sms/reference/{reference}'),
            'status_callback_url' => self::defaultStatusCallbackUrl(),
        ];
    }

    private static function defaultStatusCallbackUrl(): string
    {
        $baseUrl = rtrim((string)($_ENV['APP_BASE_URL'] ?? ''), '/');

        if ($baseUrl === '') {
            return '';
        }

        $url = $baseUrl . '/status_callback.php';
        $secret = trim((string)($_ENV['STATUS_CALLBACK_SECRET'] ?? ''));

        if ($secret !== '') {
            $url .= '?token=' . rawurlencode($secret);
        }

        return $url;
    }

    private static function resolveMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        $isDevMode = filter_var($_ENV['DEV_MODE'] ?? false, FILTER_VALIDATE_BOOL);

        if ($normalized === '') {
            return $isDevMode ? 'mock' : 'real';
        }

        if (!$isDevMode && $normalized === 'mock') {
            return 'real';
        }

        return $normalized;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}