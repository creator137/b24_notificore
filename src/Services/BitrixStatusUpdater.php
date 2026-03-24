<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\App\AppConfig;
use App\Infrastructure\Bitrix\BitrixAppClient;
use App\Infrastructure\Persistence\PortalRepository;

final class BitrixStatusUpdater
{
    public function __construct(
        private readonly PortalRepository $portalRepository,
        private readonly SendLogger $logger,
        private readonly AppConfig $config,
    ) {
    }

    public function pushStatus(array $message, string $providerStatus): ?array
    {
        $memberId = (string)($message['member_id'] ?? '');
        $bitrixMessageId = (string)($message['bitrix_message_id'] ?? '');
        $bitrixStatus = $this->mapStatus($providerStatus);

        if ($memberId === '' || $bitrixMessageId === '' || $bitrixStatus === null) {
            return null;
        }

        $portal = $this->portalRepository->findByMemberId($memberId);

        if ($portal === null) {
            return null;
        }

        if (
            (string)($portal['domain'] ?? '') === 'local.test'
            || (string)($portal['access_token'] ?? '') === 'mock-token'
        ) {
            return [
                'bitrix_status' => $bitrixStatus,
                'skipped' => 'dev portal',
            ];
        }

        try {
            $client = new BitrixAppClient(
                domain: (string)$portal['domain'],
                accessToken: (string)$portal['access_token'],
                refreshToken: (string)($portal['refresh_token'] ?? ''),
                clientId: (string)($_ENV['B24_APP_CLIENT_ID'] ?? ''),
                clientSecret: (string)($_ENV['B24_APP_CLIENT_SECRET'] ?? ''),
                verifySsl: $this->config->bitrixVerifySsl,
                oauthBaseUrl: $this->config->bitrixOauthBaseUrl,
            );

            if ((int)($portal['expires_at'] ?? 0) > 0 && (int)$portal['expires_at'] <= (time() + 60)) {
                $client->refreshTokens();
            }

            $result = $client->updateMessageStatus(
                code: (string)($_ENV['B24_SENDER_CODE'] ?? 'notificore_sms'),
                messageId: $bitrixMessageId,
                status: $bitrixStatus,
            );

            $auth = $client->authState();
            if (($auth['access_token'] ?? '') !== ($portal['access_token'] ?? '')) {
                $portal['access_token'] = $auth['access_token'];
                $portal['refresh_token'] = $auth['refresh_token'] ?? ($portal['refresh_token'] ?? '');
                $portal['updated_at'] = date('c');
                $this->portalRepository->save($portal);
            }

            return [
                'bitrix_status' => $bitrixStatus,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            $this->logger->log([
                'ts' => date('c'),
                'type' => 'bitrix_status_update_error',
                'member_id' => $memberId,
                'bitrix_message_id' => $bitrixMessageId,
                'provider_status' => $providerStatus,
                'bitrix_status' => $bitrixStatus,
                'error_message' => $e->getMessage(),
            ]);

            return [
                'bitrix_status' => $bitrixStatus,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function mapStatus(string $providerStatus): ?string
    {
        $status = strtolower(trim($providerStatus));

        return match ($status) {
            'delivered' => 'delivered',
            'undelivered', 'expired' => 'undelivered',
            'failed', 'rejected', 'cancelled', 'canceled', 'error', 'http_error', 'invalid', 'blocked' => 'failed',
            default => null,
        };
    }
}
