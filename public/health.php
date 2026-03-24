<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $container = app_container();
    $config = $container->config();
    $container->pdo();

    echo json_encode([
        'success' => true,
        'app' => 'b24-notificore',
        'time' => date('c'),
        'storage_dir' => $config->storageDir,
        'db_driver' => $config->dbDriver,
        'app_base_url' => $config->appBaseUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
