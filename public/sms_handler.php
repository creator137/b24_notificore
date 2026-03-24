<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Application\HandleSmsAction;
use App\Support\HttpRequest;

header('Content-Type: application/json; charset=utf-8');

try {
    $container = app_container();
    $action = new HandleSmsAction(
        portalRepository: $container->portalRepository(),
        settingsRepository: $container->settingsRepository(),
        messageRepository: $container->messageRepository(),
        logger: $container->logger(),
        bitrixStatusUpdater: $container->bitrixStatusUpdater(),
    );

    $result = $action(HttpRequest::payload());

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
