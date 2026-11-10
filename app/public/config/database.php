<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => ['driver' => 'mysql', 'host' => env('DATABASE_HOST', 'database'), 'port' => env('DATABASE_PORT', 3306),
            'database' => env('DATABASE_NAME', 'vault'), 'username' => env('DATABASE_USER', 'vault'), 'password' => env('DATABASE_PASSWORD'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
        'sqlite' => ['driver' => 'sqlite', 'database' => env('DB_DATABASE', ':memory:'), 'prefix' => '', 'foreign_key_constraints' => true],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
