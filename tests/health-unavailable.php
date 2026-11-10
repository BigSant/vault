<?php

declare(strict_types=1);

// No real credentials. The sentinel must never appear in a public failure response.
putenv('DATABASE_HOST=127.0.0.1');
putenv('DATABASE_PORT=1');
putenv('DATABASE_PASSWORD=vault-test-sentinel');
ob_start();
require __DIR__ . '/../app/public/health.php';
$body = ob_get_clean();
if (http_response_code() !== 503 || $body !== '{"status":"unavailable","stage":"foundation"}') {
    fwrite(STDERR, "Readiness failure did not return the safe response.\n");
    exit(1);
}
echo "Readiness failure response: OK\n";
