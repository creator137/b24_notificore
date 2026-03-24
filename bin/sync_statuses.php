<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Application\SyncPendingStatusesAction;

$memberId = null;
$limit = 20;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--member=')) {
        $memberId = substr($argument, 9);
    }

    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int)substr($argument, 8));
    }
}

$container = app_container();
$action = new SyncPendingStatusesAction(
    settingsRepository: $container->settingsRepository(),
    messageRepository: $container->messageRepository(),
    logger: $container->logger(),
    bitrixStatusUpdater: $container->bitrixStatusUpdater(),
);

$result = $action($memberId, $limit);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
