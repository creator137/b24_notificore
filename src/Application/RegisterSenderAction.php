<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Bitrix\BitrixAppClient;

final class RegisterSenderAction
{
    public function __invoke(array $portal): array
    {
        $baseUrl = rtrim((string)($_ENV['APP_BASE_URL'] ?? ''), '/');
        $senderCode = (string)($_ENV['B24_SENDER_CODE'] ?? 'notificore_sms');
        $senderName = (string)($_ENV['B24_SENDER_NAME'] ?? 'Notificore SMS');
        $devInstallMock = filter_var($_ENV['DEV_INSTALL_MOCK'] ?? false, FILTER_VALIDATE_BOOL);

        if ($baseUrl === '') {
            throw new \RuntimeException('APP_BASE_URL is empty');
        }

        $handlerUrl = $baseUrl . '/sms_handler.php';

        if (
            $devInstallMock === true
            || (($portal['domain'] ?? '') === 'local.test')
            || (($portal['access_token'] ?? '') === 'mock-token')
        ) {
            return [
                'mock' => true,
                'registered' => true,
                'code' => $senderCode,
                'name' => $senderName,
                'handler' => $handlerUrl,
            ];
        }

        $client = new BitrixAppClient(
            domain: (string)$portal['domain'],
            accessToken: (string)$portal['access_token'],
            refreshToken: (string)($portal['refresh_token'] ?? ''),
            clientId: (string)($_ENV['B24_APP_CLIENT_ID'] ?? ''),
            clientSecret: (string)($_ENV['B24_APP_CLIENT_SECRET'] ?? ''),
            verifySsl: filter_var($_ENV['B24_VERIFY_SSL'] ?? true, FILTER_VALIDATE_BOOL),
            oauthBaseUrl: (string)($_ENV['B24_OAUTH_BASE_URL'] ?? 'https://oauth.bitrix.info/oauth/token'),
        );

        return $client->ensureSmsSenderRegistered(
            code: $senderCode,
            name: $senderName,
            handlerUrl: $handlerUrl
        );
    }
}
