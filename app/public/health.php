<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Readiness only: never create a schema or return connection errors/credentials.
try {
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('Missing encryption extension');
    }
    $database = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DATABASE_HOST') ?: 'database',
            getenv('DATABASE_PORT') ?: '3306',
            getenv('DATABASE_NAME') ?: 'vault'),
        getenv('DATABASE_USER') ?: '',
        getenv('DATABASE_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2],
    );
    $database->query('SELECT 1');
    echo '{"status":"ready","stage":"foundation"}';
} catch (Throwable) {
    http_response_code(503);
    echo '{"status":"unavailable","stage":"foundation"}';
}
