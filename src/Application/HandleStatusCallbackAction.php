<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\MessageRepository;
use App\Services\BitrixStatusUpdater;
use App\Services\SendLogger;
use App\Support\BitrixRequest;

final class HandleStatusCallbackAction
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly SendLogger $logger,
        private readonly BitrixStatusUpdater $bitrixStatusUpdater,
    ) {
    }

    public function __invoke(array $payload): array
    {
        $providerMessageId = BitrixRequest::resolveProviderMessageId($payload);
        $providerReference = BitrixRequest::resolveProviderReference($payload);
        $status = strtolower(BitrixRequest::resolveStatus($payload));

        if ($providerMessageId === '' && $providerReference === '') {
            throw new \RuntimeException('provider_message_id or reference is required');
        }

        $existing = $providerMessageId !== ''
            ? $this->messageRepository->findByProviderMessageId($providerMessageId)
            : null;

        if ($existing === null && $providerReference !== '') {
            $existing = $this->messageRepository->findByProviderReference($providerReference);
        }

        if ($existing === null) {
            $record = [
                'ts' => date('c'),
                'provider_message_id' => $providerMessageId,
                'provider_reference' => $providerReference,
                'status' => $status,
                'callback_only' => true,
                'raw_payload' => $payload,
            ];

            $this->logger->log($record);

            return [
                'success' => true,
                'message' => 'Status callback received, but message was not found in storage',
                'data' => $record,
            ];
        }

        $patch = [
            'status' => $status,
            'status_updated_at' => date('c'),
            'status_payload' => $payload,
        ];

        if ($providerMessageId !== '' && (string)($existing['provider_message_id'] ?? '') === '') {
            $patch['provider_message_id'] = $providerMessageId;
        }

        if ($providerReference !== '' && (string)($existing['provider_reference'] ?? '') === '') {
            $patch['provider_reference'] = $providerReference;
        }

        $this->messageRepository->updateById((int)$existing['id'], $patch);
        $updated = $this->messageRepository->findById((int)$existing['id']) ?? array_replace($existing, $patch);
        $bitrixUpdate = $this->bitrixStatusUpdater->pushStatus($updated, $status);

        $this->logger->log([
            'ts' => date('c'),
            'type' => 'status_callback',
            'provider_message_id' => $providerMessageId,
            'provider_reference' => $providerReference,
            'status' => $status,
            'bitrix_status_update' => $bitrixUpdate,
            'raw_payload' => $payload,
        ]);

        return [
            'success' => true,
            'message' => 'Status updated',
            'data' => $updated,
            'bitrix_status_update' => $bitrixUpdate,
        ];
    }
}
