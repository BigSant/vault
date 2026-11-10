<?php
return [
    'name' => 'Vault', 'env' => env('APP_ENV', 'production'), 'debug' => false,
    'url' => env('APP_URL', 'https://vault.local'), 'timezone' => 'UTC', 'locale' => 'lt',
    'fallback_locale' => 'en', 'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY') ?: (is_file('/run/vault/app.key') ? trim(file_get_contents('/run/vault/app.key')) : null),
    'previous_keys' => [], 'maintenance' => ['driver' => 'file'],
];
