<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\App\AppConfig;
use PDO;
use PDOException;
use RuntimeException;

final class DatabaseFactory
{
    public static function create(AppConfig $config): PDO
    {
        self::ensureDirectory($config->storageDir);
        self::ensureDirectory($config->logsDir());

        try {
            return match ($config->dbDriver) {
                'sqlite' => self::createSqlite($config),
                'mysql' => self::createMysql($config),
                default => throw new RuntimeException('Unsupported DB_DRIVER: ' . $config->dbDriver),
            };
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function createSqlite(AppConfig $config): PDO
    {
        self::ensureDirectory(dirname($config->dbDatabase));

        $pdo = new PDO('sqlite:' . $config->dbDatabase);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private static function createMysql(AppConfig $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
            $config->dbCharset
        );

        $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create directory: ' . $path);
        }
    }
}
