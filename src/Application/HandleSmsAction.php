<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\MessageRepository;
use App\Infrastructure\Persistence\PortalRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use App\Services\BitrixStatusUpdater;
use App\Services\NotificationClientFactory;
use App\Services\SendLogger;
use App\Support\BitrixRequest;

final class HandleSmsAction
{
    public function __construct(
        private readonly PortalRepository $portalRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly MessageRepository $messageRepository,
        private readonly SendLogger $logger,
        private readonly BitrixStatusUpdater $bitrixStatusUpdater,
    ) {
    }

    public function __invoke(array $payload): array
    {
        $incomingPortal = BitrixRequest::extractPortal($payload);

        if (
            (string)($incomingPortal['member_id'] ?? '') !== ''
            && (string)($incomingPortal['domain'] ?? '') !== ''
            && (string)($incomingPortal['access_token'] ?? '') !== ''
        ) {
            $this->portalRepository->save($incomingPortal);
        }

        $memberId = BitrixRequest::resolveMemberId($payload);
        $domain = BitrixRequest::resolveDomain($payload);

        if ($memberId !== '') {
            $portal = $this->portalRepository->findByMemberId($memberId);
        } elseif ($domain !== '') {
            $portal = $this->portalRepository->findByDomain($domain);
        } else {
            $portal = $this->portalRepository->findFirst();
        }

        if (!$portal) {
            throw new \RuntimeException('Portal is not registered');
        }

        $applicationToken = BitrixRequest::resolveApplicationToken($payload);
        if (
            $applicationToken !== ''
            && (string)($portal['application_token'] ?? '') !== ''
            && $applicationToken !== (string)$portal['application_token']
        ) {
            throw new \RuntimeException('Invalid application_token');
        }

        $settings = $this->settingsRepository->findByMemberId((string)$portal['member_id']);
        $client = NotificationClientFactory::makeFromSettings($settings);

        $phone = BitrixRequest::resolvePhone($payload);
        $message = BitrixRequest::resolveMessageBody($payload);
        $bitrixMessageId = BitrixRequest::resolveBitrixMessageId($payload);

        if ($phone === '' || $message === '') {
            throw new \RuntimeException('Incoming payload does not contain phone or message');
        }

        $sendResult = $client->sendSms($phone, $message);
        $record = [
            'ts' => date('c'),
            'member_id' => (string)$portal['member_id'],
            'bitrix_message_id' => $bitrixMessageId,
            'provider_message_id' => (string)($sendResult['provider_message_id'] ?? ''),
            'provider_reference' => (string)($sendResult['provider_reference'] ?? ''),
            'phone' => $phone,
            'message' => $message,
            'status' => (string)($sendResult['status'] ?? 'unknown'),
            'channel' => 'sms',
            'source' => (string)($payload['source'] ?? ($bitrixMessageId !== '' ? 'bitrix24' : 'manual')),
            'is_test' => (bool)($payload['is_test'] ?? false),
            'error_message' => (string)($sendResult['error_message'] ?? ''),
            'send_result' => $sendResult,
            'raw_payload' => $payload,
        ];

        $stored = $this->messageRepository->add($record);

        if (!($sendResult['success'] ?? false)) {
            $bitrixUpdate = $this->bitrixStatusUpdater->pushStatus($stored, (string)$record['status']);

            if ($bitrixUpdate !== null) {
                $stored['send_result']['bitrix_status_update'] = $bitrixUpdate;
                $this->messageRepository->updateById((int)$stored['id'], [
                    'send_result' => $stored['send_result'],
                ]);
            }
        }

        $this->logger->log($stored);

        return [
            'success' => (bool)($sendResult['success'] ?? false),
            'data' => $this->messageRepository->findById((int)$stored['id']) ?? $stored,
        ];
    }
}
