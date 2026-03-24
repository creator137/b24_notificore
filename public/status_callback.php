<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Application\HandleStatusCallbackAction;
use App\Support\HttpRequest;

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = HttpRequest::payload();
    $expectedToken = trim((string)($_ENV['STATUS_CALLBACK_SECRET'] ?? ''));
    $receivedToken = trim((string)($payload['token'] ?? ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '')));

    if ($expectedToken !== '' && $receivedToken !== $expectedToken) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'error' => 'Invalid callback token',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $container = app_container();
    $action = new HandleStatusCallbackAction(
        messageRepository: $container->messageRepository(),
        logger: $container->logger(),
        bitrixStatusUpdater: $container->bitrixStatusUpdater(),
    );

    $result = $action($payload);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
