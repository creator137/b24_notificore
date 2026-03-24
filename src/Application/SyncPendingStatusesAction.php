<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\MessageRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use App\Services\BitrixStatusUpdater;
use App\Services\NotificationClientFactory;
use App\Services\NotificoreClient;
use App\Services\SendLogger;

final class SyncPendingStatusesAction
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly MessageRepository $messageRepository,
        private readonly SendLogger $logger,
        private readonly BitrixStatusUpdater $bitrixStatusUpdater,
    ) {
    }

    public function __invoke(?string $memberId = null, int $limit = 20): array
    {
        $messages = $this->messageRepository->findPendingForStatusSync($memberId, $limit);
        $updated = [];
        $skipped = [];

        foreach ($messages as $message) {
            $settings = $this->settingsRepository->findByMemberId((string)$message['member_id']);
            $client = NotificationClientFactory::makeFromSettings($settings);

            if (!$client instanceof NotificoreClient) {
                $skipped[] = [
                    'id' => $message['id'],
                    'reason' => 'status sync is available only for real Notificore mode',
                ];
                continue;
            }

            $statusResult = null;

            if ((string)($message['provider_message_id'] ?? '') !== '') {
                $statusResult = $client->getSmsStatus((string)$message['provider_message_id']);
            } elseif ((string)($message['provider_reference'] ?? '') !== '') {
                $statusResult = $client->getSmsStatusByReference((string)$message['provider_reference']);
            }

            if (!is_array($statusResult)) {
                $skipped[] = [
                    'id' => $message['id'],
                    'reason' => 'message does not have provider identifiers',
                ];
                continue;
            }

            $status = (string)($statusResult['status'] ?? 'unknown');

            if ($status === '' || $status === 'unknown') {
                $skipped[] = [
                    'id' => $message['id'],
                    'reason' => (string)($statusResult['error_message'] ?? 'provider returned unknown status'),
                ];
                continue;
            }

            $patch = [
                'status' => $status,
                'status_updated_at' => date('c'),
                'status_payload' => [
                    'source' => 'manual_sync',
                    'provider_result' => $statusResult,
                ],
            ];

            if ((string)($statusResult['provider_message_id'] ?? '') !== '' && (string)($message['provider_message_id'] ?? '') === '') {
                $patch['provider_message_id'] = (string)$statusResult['provider_message_id'];
            }

            if ((string)($statusResult['provider_reference'] ?? '') !== '' && (string)($message['provider_reference'] ?? '') === '') {
                $patch['provider_reference'] = (string)$statusResult['provider_reference'];
            }

            $this->messageRepository->updateById((int)$message['id'], $patch);
            $current = $this->messageRepository->findById((int)$message['id']) ?? array_replace($message, $patch);
            $bitrixUpdate = $this->bitrixStatusUpdater->pushStatus($current, $status);

            $updated[] = [
                'id' => $current['id'],
                'provider_message_id' => $current['provider_message_id'],
                'status' => $status,
                'bitrix_status_update' => $bitrixUpdate,
            ];

            $this->logger->log([
                'ts' => date('c'),
                'type' => 'status_sync',
                'message_id' => $current['id'],
                'provider_message_id' => $current['provider_message_id'],
                'status' => $status,
                'bitrix_status_update' => $bitrixUpdate,
            ]);
        }

        return [
            'success' => true,
            'total' => count($messages),
            'updated_count' => count($updated),
            'skipped_count' => count($skipped),
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }
}
