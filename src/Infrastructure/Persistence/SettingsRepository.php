<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\Json;
use PDO;

final class SettingsRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function save(string $memberId, array $settings): void
    {
        $memberId = trim($memberId);

        if ($memberId === '') {
            throw new \RuntimeException('member_id is required');
        }

        $existing = $this->findByMemberId($memberId);
        $normalized = array_replace($existing, $settings);
        $payload = [
            'member_id' => $memberId,
            'settings_json' => Json::encode($normalized),
            'updated_at' => date('c'),
        ];

        if ($existing === []) {
            $sql = 'INSERT INTO settings (member_id, settings_json, updated_at) VALUES (:member_id, :settings_json, :updated_at)';
        } else {
            $sql = 'UPDATE settings SET settings_json = :settings_json, updated_at = :updated_at WHERE member_id = :member_id';
        }

        $this->pdo->prepare($sql)->execute($payload);
    }

    public function findByMemberId(string $memberId): array
    {
        $statement = $this->pdo->prepare('SELECT settings_json FROM settings WHERE member_id = :member_id LIMIT 1');
        $statement->execute(['member_id' => $memberId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return [];
        }

        return Json::decodeArray($row['settings_json'] ?? null);
    }
}
