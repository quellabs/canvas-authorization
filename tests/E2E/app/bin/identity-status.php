<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo json_encode([
    'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'identities' => (int)$pdo->query('SELECT COUNT(*) FROM oidc_identities')->fetchColumn(),
    'linked_user_id' => (int)$pdo->query('SELECT local_user_id FROM oidc_identities LIMIT 1')->fetchColumn(),
], JSON_THROW_ON_ERROR);
