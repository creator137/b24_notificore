<?php

declare(strict_types=1);

use App\Infrastructure\App\AppContainer;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Moscow');

if (!function_exists('app_container')) {
    function app_container(): AppContainer
    {
        static $container = null;

        if (!$container instanceof AppContainer) {
            $container = AppContainer::boot(__DIR__);
        }

        return $container;
    }
}
