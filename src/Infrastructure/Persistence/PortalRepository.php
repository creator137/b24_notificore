<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\Json;
use PDO;

final class PortalRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function save(array $portal): void
    {
        $memberId = trim((string)($portal['member_id'] ?? ''));

        if ($memberId === '') {
            throw new \RuntimeException('member_id is required');
        }

        $existing = $this->findByMemberId($memberId) ?? [];

        $row = [
            'member_id' => $memberId,
            'domain' => trim((string)($portal['domain'] ?? $existing['domain'] ?? '')),
            'access_token' => (string)($portal['access_token'] ?? $existing['access_token'] ?? ''),
            'refresh_token' => (string)($portal['refresh_token'] ?? $existing['refresh_token'] ?? ''),
            'application_token' => (string)($portal['application_token'] ?? $existing['application_token'] ?? ''),
            'expires_at' => (int)($portal['expires_at'] ?? $existing['expires_at'] ?? 0),
            'installed_at' => (string)($existing['installed_at'] ?? $portal['installed_at'] ?? date('c')),
            'updated_at' => date('c'),
            'raw_payload_json' => Json::encode($portal['raw'] ?? $existing['raw'] ?? []),
        ];

        if ($existing === []) {
            $sql = 'INSERT INTO portals (
                member_id,
                domain,
                access_token,
                refresh_token,
                application_token,
                expires_at,
                installed_at,
                updated_at,
                raw_payload_json
            ) VALUES (
                :member_id,
                :domain,
                :access_token,
                :refresh_token,
                :application_token,
                :expires_at,
                :installed_at,
                :updated_at,
                :raw_payload_json
            )';
        } else {
            $sql = 'UPDATE portals SET
                domain = :domain,
                access_token = :access_token,
                refresh_token = :refresh_token,
                application_token = :application_token,
                expires_at = :expires_at,
                installed_at = :installed_at,
                updated_at = :updated_at,
                raw_payload_json = :raw_payload_json
            WHERE member_id = :member_id';
        }

        $this->pdo->prepare($sql)->execute($row);
    }

    public function findByMemberId(string $memberId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM portals WHERE member_id = :member_id LIMIT 1');
        $statement->execute(['member_id' => $memberId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByDomain(string $domain): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM portals WHERE domain = :domain LIMIT 1');
        $statement->execute(['domain' => $domain]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findFirst(): ?array
    {
        $statement = $this->pdo->query('SELECT * FROM portals ORDER BY updated_at DESC, installed_at DESC LIMIT 1');
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM portals ORDER BY installed_at DESC');
        $rows = $statement->fetchAll();

        return array_map([$this, 'hydrate'], $rows);
    }

    private function hydrate(array $row): array
    {
        return [
            'member_id' => (string)$row['member_id'],
            'domain' => (string)($row['domain'] ?? ''),
            'access_token' => (string)($row['access_token'] ?? ''),
            'refresh_token' => (string)($row['refresh_token'] ?? ''),
            'application_token' => (string)($row['application_token'] ?? ''),
            'expires_at' => (int)($row['expires_at'] ?? 0),
            'installed_at' => (string)($row['installed_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'raw' => Json::decodeArray($row['raw_payload_json'] ?? null),
        ];
    }
}
