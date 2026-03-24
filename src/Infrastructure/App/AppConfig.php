<?php

declare(strict_types=1);

namespace App\Infrastructure\App;

final class AppConfig
{
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $appBaseUrl,
        public readonly string $storageDir,
        public readonly string $logPath,
        public readonly string $dbDriver,
        public readonly string $dbDatabase,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $dbCharset,
        public readonly bool $bitrixVerifySsl,
        public readonly string $bitrixOauthBaseUrl,
    ) {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        $storageDir = self::resolvePath(
            $projectRoot,
            (string)($_ENV['APP_STORAGE_DIR'] ?? 'storage')
        );

        $logPath = self::resolvePath(
            $projectRoot,
            (string)($_ENV['LOG_PATH'] ?? 'logs/send_log.jsonl')
        );

        $dbDriver = strtolower((string)($_ENV['DB_DRIVER'] ?? 'sqlite'));
        $dbDatabase = (string)($_ENV['DB_DATABASE'] ?? '');

        if ($dbDriver === 'sqlite') {
            $dbDatabase = $dbDatabase !== ''
                ? self::resolvePath($projectRoot, $dbDatabase)
                : $storageDir . DIRECTORY_SEPARATOR . 'notificore.sqlite';
        }

        return new self(
            projectRoot: $projectRoot,
            appBaseUrl: rtrim((string)($_ENV['APP_BASE_URL'] ?? ''), '/'),
            storageDir: $storageDir,
            logPath: $logPath,
            dbDriver: $dbDriver,
            dbDatabase: $dbDatabase,
            dbHost: (string)($_ENV['DB_HOST'] ?? '127.0.0.1'),
            dbPort: (int)($_ENV['DB_PORT'] ?? 3306),
            dbName: (string)($_ENV['DB_NAME'] ?? ''),
            dbUser: (string)($_ENV['DB_USER'] ?? ''),
            dbPassword: (string)($_ENV['DB_PASSWORD'] ?? ''),
            dbCharset: (string)($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
            bitrixVerifySsl: self::toBool($_ENV['B24_VERIFY_SSL'] ?? '1'),
            bitrixOauthBaseUrl: rtrim((string)($_ENV['B24_OAUTH_BASE_URL'] ?? 'https://oauth.bitrix.info/oauth/token/'), '/'),
        );
    }

    public function logsDir(): string
    {
        return dirname($this->logPath);
    }

    public function handlerUrl(string $scriptName): string
    {
        if ($this->appBaseUrl === '') {
            return '';
        }

        return $this->appBaseUrl . '/' . ltrim($scriptName, '/');
    }

    public function defaultStatusCallbackUrl(): string
    {
        return $this->handlerUrl('status_callback.php');
    }

    public static function resolvePath(string $projectRoot, string $path): string
    {
        if ($path === '') {
            return $projectRoot;
        }

        if (preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $path) === 1) {
            return $path;
        }

        return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}
