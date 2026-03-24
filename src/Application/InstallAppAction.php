<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\PortalRepository;
use App\Support\BitrixRequest;

final class InstallAppAction
{
    public function __construct(
        private readonly PortalRepository $portalRepository,
        private readonly RegisterSenderAction $registerSenderAction,
    ) {
    }

    public function __invoke(array $payload): array
    {
        $portal = BitrixRequest::extractPortal($payload);

        if (
            (string)($portal['access_token'] ?? '') === ''
            || (string)($portal['domain'] ?? '') === ''
            || (string)($portal['member_id'] ?? '') === ''
        ) {
            throw new \RuntimeException('Install payload is incomplete');
        }

        $this->portalRepository->save($portal);
        $senderResult = ($this->registerSenderAction)($portal);

        return [
            'portal' => $this->portalRepository->findByMemberId((string)$portal['member_id']) ?? $portal,
            'sender_result' => $senderResult,
        ];
    }
}
