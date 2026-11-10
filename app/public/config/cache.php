<?php

return ['default' => env('CACHE_STORE', 'database'), 'stores' => [
    'database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_table' => 'cache_locks', 'lock_connection' => null],
    'array' => ['driver' => 'array', 'serialize' => false]], 'prefix' => 'vault:'];
