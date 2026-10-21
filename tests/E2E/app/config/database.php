<?php

return [
    'driver' => 'mysql',
    'host' => getenv('E2E_DB_HOST') ?: '127.0.0.1',
    'database' => getenv('E2E_DB_NAME') ?: 'canvas_authorization_e2e',
    'username' => getenv('E2E_DB_USER') ?: 'root',
    'password' => getenv('E2E_DB_PASS') ?: '',
    'port' => (int)(getenv('E2E_DB_PORT') ?: 3306),
    'encoding' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'entity_namespace' => 'App\\Entities',
    'entity_path' => dirname(__DIR__) . '/src/Entities',
    'proxy_path' => dirname(__DIR__) . '/storage/objectquel/proxies',
    'migrations_path' => dirname(__DIR__) . '/migrations',
    'slow_query_log' => null,
    'slow_query_threshold' => 0.0,
];
