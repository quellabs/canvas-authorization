<?php

declare(strict_types=1);

$database = getenv('E2E_DB_NAME') ?: '';

if (preg_match('/^canvas_authorization_e2e_[a-f0-9]{12}$/', $database) !== 1) {
    fwrite(STDERR, "Refusing an invalid E2E database name.\n");
    exit(1);
}

$host = getenv('E2E_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('E2E_DB_PORT') ?: 3306);
$user = getenv('E2E_DB_USER') ?: 'root';
$password = getenv('E2E_DB_PASS') ?: '';
$pdo = new PDO("mysql:host={$host};port={$port}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if (($argv[1] ?? '') === 'create') {
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} elseif (($argv[1] ?? '') === 'drop') {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
} else {
    fwrite(STDERR, "Expected create or drop.\n");
    exit(1);
}
