<?php

return ['bao_url' => env('BAO_ADDR', 'http://openbao:8200'), 'credentials' => env('BAO_CREDENTIALS', '/run/vault/bao.json'),
    'broker_url' => env('BROKER_URL', 'http://broker:8010'), 'broker_key' => env('BROKER_KEY_FILE', '/run/vault/broker.key'),
    'bootstrap_file' => env('VAULT_BOOTSTRAP_FILE', '/run/vault/bootstrap.code'),
    'connectors' => env('VAULT_CONNECTORS', '/opt/vault/connectors.json')];
