<?php

declare(strict_types=1);

namespace App\Infrastructure\App;

use App\Infrastructure\Database\DatabaseBootstrapper;
use App\Infrastructure\Database\DatabaseFactory;
use App\Infrastructure\Persistence\MessageRepository;
use App\Infrastructure\Persistence\PortalRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use App\Services\BitrixStatusUpdater;
use App\Services\SendLogger;
use PDO;

final class AppContainer
{
    private static ?self $instance = null;

    private ?PDO $pdo = null;
    private ?PortalRepository $portalRepository = null;
    private ?SettingsRepository $settingsRepository = null;
    private ?MessageRepository $messageRepository = null;
    private ?SendLogger $logger = null;
    private ?BitrixStatusUpdater $bitrixStatusUpdater = null;

    private function __construct(
        private readonly AppConfig $config
    ) {
    }

    public static function boot(string $projectRoot): self
    {
        if (self::$instance === null) {
            self::$instance = new self(AppConfig::fromEnvironment($projectRoot));
        }

        self::$instance->pdo();

        return self::$instance;
    }

    public function config(): AppConfig
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = DatabaseFactory::create($this->config);
            (new DatabaseBootstrapper(
                pdo: $this->pdo,
                dbDriver: $this->config->dbDriver,
                storageDir: $this->config->storageDir,
            ))->boot();
        }

        return $this->pdo;
    }

    public function portalRepository(): PortalRepository
    {
        return $this->portalRepository ??= new PortalRepository($this->pdo());
    }

    public function settingsRepository(): SettingsRepository
    {
        return $this->settingsRepository ??= new SettingsRepository($this->pdo());
    }

    public function messageRepository(): MessageRepository
    {
        return $this->messageRepository ??= new MessageRepository($this->pdo());
    }

    public function logger(): SendLogger
    {
        return $this->logger ??= new SendLogger($this->config->logPath);
    }

    public function bitrixStatusUpdater(): BitrixStatusUpdater
    {
        return $this->bitrixStatusUpdater ??= new BitrixStatusUpdater(
            portalRepository: $this->portalRepository(),
            logger: $this->logger(),
            config: $this->config,
        );
    }
}
