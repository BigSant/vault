<?php

return ['driver' => env('SESSION_DRIVER', 'database'), 'lifetime' => 20, 'expire_on_close' => true, 'encrypt' => true,
    'files' => storage_path('framework/sessions'), 'connection' => null, 'table' => 'sessions', 'store' => null, 'lottery' => [2, 100],
    'cookie' => '__Host-vault', 'path' => '/', 'domain' => null, 'secure' => true, 'http_only' => true, 'same_site' => 'strict', 'partitioned' => false, 'serialization' => 'json'];
