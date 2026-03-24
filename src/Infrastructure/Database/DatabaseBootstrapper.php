<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Persistence\JsonFileStore;
use App\Infrastructure\Persistence\MessageRepository;
use App\Infrastructure\Persistence\PortalRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use PDO;

final class DatabaseBootstrapper
{
    private const IMPORT_FLAG = 'legacy_json_imported_v1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dbDriver,
        private readonly string $storageDir,
    ) {
    }

    public function boot(): void
    {
        foreach ($this->schemaSql() as $sql) {
            $this->pdo->exec($sql);
        }

        $this->importLegacyJsonIfNeeded();
    }

    private function schemaSql(): array
    {
        if ($this->dbDriver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS system_meta (
                    meta_key VARCHAR(191) PRIMARY KEY,
                    meta_value LONGTEXT NULL,
                    updated_at VARCHAR(40) NOT NULL
                )',
                'CREATE TABLE IF NOT EXISTS portals (
                    member_id VARCHAR(191) PRIMARY KEY,
                    domain VARCHAR(255) NOT NULL,
                    access_token TEXT NOT NULL,
                    refresh_token TEXT NOT NULL,
                    application_token VARCHAR(255) NOT NULL,
                    expires_at BIGINT NOT NULL DEFAULT 0,
                    installed_at VARCHAR(40) NOT NULL,
                    updated_at VARCHAR(40) NOT NULL,
                    raw_payload_json LONGTEXT NULL
                )',
                'CREATE TABLE IF NOT EXISTS settings (
                    member_id VARCHAR(191) PRIMARY KEY,
                    settings_json LONGTEXT NOT NULL,
                    updated_at VARCHAR(40) NOT NULL
                )',
                'CREATE TABLE IF NOT EXISTS messages (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    member_id VARCHAR(191) NOT NULL,
                    bitrix_message_id VARCHAR(191) NOT NULL,
                    provider_message_id VARCHAR(191) NOT NULL,
                    provider_reference VARCHAR(191) NOT NULL,
                    phone VARCHAR(64) NOT NULL,
                    message_text LONGTEXT NOT NULL,
                    status VARCHAR(64) NOT NULL,
                    channel VARCHAR(32) NOT NULL,
                    source VARCHAR(64) NOT NULL,
                    is_test TINYINT(1) NOT NULL DEFAULT 0,
                    created_at VARCHAR(40) NOT NULL,
                    status_updated_at VARCHAR(40) NOT NULL,
                    error_message TEXT NOT NULL,
                    send_result_json LONGTEXT NULL,
                    raw_payload_json LONGTEXT NULL,
                    status_payload_json LONGTEXT NULL,
                    INDEX idx_messages_member_id (member_id),
                    INDEX idx_messages_provider_message_id (provider_message_id),
                    INDEX idx_messages_provider_reference (provider_reference),
                    INDEX idx_messages_created_at (created_at)
                )',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS system_meta (
                meta_key TEXT PRIMARY KEY,
                meta_value TEXT,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS portals (
                member_id TEXT PRIMARY KEY,
                domain TEXT NOT NULL,
                access_token TEXT NOT NULL,
                refresh_token TEXT NOT NULL,
                application_token TEXT NOT NULL,
                expires_at INTEGER NOT NULL DEFAULT 0,
                installed_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                raw_payload_json TEXT
            )',
            'CREATE TABLE IF NOT EXISTS settings (
                member_id TEXT PRIMARY KEY,
                settings_json TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id TEXT NOT NULL,
                bitrix_message_id TEXT NOT NULL,
                provider_message_id TEXT NOT NULL,
                provider_reference TEXT NOT NULL,
                phone TEXT NOT NULL,
                message_text TEXT NOT NULL,
                status TEXT NOT NULL,
                channel TEXT NOT NULL,
                source TEXT NOT NULL,
                is_test INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                status_updated_at TEXT NOT NULL,
                error_message TEXT NOT NULL,
                send_result_json TEXT,
                raw_payload_json TEXT,
                status_payload_json TEXT
            )',
            'CREATE INDEX IF NOT EXISTS idx_messages_member_id ON messages(member_id)',
            'CREATE INDEX IF NOT EXISTS idx_messages_provider_message_id ON messages(provider_message_id)',
            'CREATE INDEX IF NOT EXISTS idx_messages_provider_reference ON messages(provider_reference)',
            'CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at)',
        ];
    }

    private function importLegacyJsonIfNeeded(): void
    {
        if ($this->meta(self::IMPORT_FLAG) !== null) {
            return;
        }

        $store = new JsonFileStore($this->storageDir);
        $portalRepository = new PortalRepository($this->pdo);
        $settingsRepository = new SettingsRepository($this->pdo);
        $messageRepository = new MessageRepository($this->pdo);

        foreach ($store->read('portals.json') as $portal) {
            if (is_array($portal)) {
                $portalRepository->save($portal);
            }
        }

        foreach ($store->read('settings.json') as $memberId => $settings) {
            if (is_array($settings)) {
                $settingsRepository->save((string)$memberId, $settings);
            }
        }

        foreach ($store->read('messages.json') as $message) {
            if (is_array($message)) {
                $messageRepository->add($message);
            }
        }

        $this->setMeta(self::IMPORT_FLAG, date('c'));
    }

    private function meta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT meta_value FROM system_meta WHERE meta_key = :meta_key LIMIT 1');
        $statement->execute(['meta_key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function setMeta(string $key, string $value): void
    {
        $existing = $this->meta($key);
        $payload = [
            'meta_key' => $key,
            'meta_value' => $value,
            'updated_at' => date('c'),
        ];

        if ($existing === null) {
            $sql = 'INSERT INTO system_meta (meta_key, meta_value, updated_at) VALUES (:meta_key, :meta_value, :updated_at)';
        } else {
            $sql = 'UPDATE system_meta SET meta_value = :meta_value, updated_at = :updated_at WHERE meta_key = :meta_key';
        }

        $this->pdo->prepare($sql)->execute($payload);
    }
}
