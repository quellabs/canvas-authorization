<?php

	require_once __DIR__ . '/../vendor/autoload.php';

	use Cake\Database\Connection;
	use Cake\Database\Driver\Mysql;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;

	$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
	$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
	$user = getenv('TEST_DB_USER') ?: 'root';
	$pass = getenv('TEST_DB_PASS') ?: '';
	$database = getenv('TEST_DB_NAME') ?: 'canvas_authorization_test';

	// Same defaults as F:\Work\framework\tests\bootstrap.php (the harness this
	// file is adapted from), with its own database name so the two suites
	// never collide when both happen to run against the same MySQL server.
	$pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass);
	$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
	$pdo = null;

	$connection = new Connection([
		'driver'     => Mysql::class,
		'host'       => $host,
		'port'       => $port,
		'username'   => $user,
		'password'   => $pass,
		'database'   => $database,
		'persistent' => false,
		'init'       => ["SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"],
	]);

	$proxyDir = __DIR__ . '/../storage/integration_test_proxies';

	if (!is_dir($proxyDir)) {
		mkdir($proxyDir, 0755, true);
	}

	// Points directly at the shipped stubs — this exercises the real
	// OidcIdentityEntity annotations, not a parallel test-only copy that
	// could drift from what install:auth-oidc actually generates.
	$config = new Configuration();
	$config->setEntityNameSpace('App\\Entities');
	$config->setEntityPath(__DIR__ . '/../stubs/Entities');
	$config->setProxyDir($proxyDir);

	$GLOBALS['test_em'] = new EntityManager($config, $connection);
	$GLOBALS['test_connection'] = $connection;

	// Hand-written to match OidcIdentityEntity's @Orm\Column/@Orm\UniqueIndex
	// annotations exactly — the same approach F:\Work\framework\tests\bootstrap.php
	// uses for its own fixture tables, rather than depending on ObjectQuel's
	// migration-generation pipeline (that pipeline is ObjectQuel's own concern
	// to test, not this package's).
	$connection->execute(
		'CREATE TABLE IF NOT EXISTS `oidc_identities` (
		    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
		    `local_user_id`  INT UNSIGNED NOT NULL,
		    `issuer`         VARCHAR(512) NOT NULL,
		    `subject`        VARCHAR(255) NOT NULL,
		    `issuer_key`     CHAR(64)     NOT NULL,
		    `identity_key`   CHAR(64)     NOT NULL,
		    `created_at`     DATETIME     NOT NULL,
		    PRIMARY KEY (`id`),
		    UNIQUE KEY `uidx_oidc_identities_identity_key` (`identity_key`),
		    UNIQUE KEY `uidx_oidc_identities_user_issuer` (`local_user_id`, `issuer_key`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	');

	// Matches UserEntity's own annotations — needed so tests can exercise
	// the real user-creation + identity-link transaction, not just the
	// identity table in isolation.
	$connection->execute(
		'CREATE TABLE IF NOT EXISTS `users` (
		    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
		    `name`     VARCHAR(255) NOT NULL,
		    `username` VARCHAR(255) NOT NULL,
		    `password` VARCHAR(255) NOT NULL,
		    `banned`   TINYINT(1)   NOT NULL DEFAULT 0,
		    PRIMARY KEY (`id`),
		    UNIQUE KEY `uidx_username` (`username`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	');
