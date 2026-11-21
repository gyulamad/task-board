<?php
declare(strict_types=1);

// Prepares a MySQL/MariaDB environment for the test suite (used by CI):
//  1. imports schema.sql into the TEST_DB_* database (dropping the old one),
//  2. writes config.php at the project root so the application under test
//     connects to that database.
// Run with: php tests/bin/prepare-mysql.php

require __DIR__ . '/../bootstrap.php';

use TaskBoard\Tests\Support\Schema;
use TaskBoard\Tests\Support\TestServer;

$name = getenv('TEST_DB_NAME') ?: 'task_board_test';
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$user = getenv('TEST_DB_USER') ?: 'root';
$pass = getenv('TEST_DB_PASS') ?: '';

putenv("TEST_DB_MODE=mysql");
putenv("TEST_DB_NAME={$name}");
putenv("TEST_DB_HOST={$host}");
putenv("TEST_DB_USER={$user}");
putenv("TEST_DB_PASS={$pass}");

TestServer::resetDatabase();

$config = "<?php\nreturn [\n    'db_host' => " . var_export($host, true) . ",\n"
    . "    'db_name' => " . var_export($name, true) . ",\n"
    . "    'db_user' => " . var_export($user, true) . ",\n"
    . "    'db_pass' => " . var_export($pass, true) . ",\n"
    . "    'app_name' => 'Task Board',\n"
    . "    'max_upload_bytes' => 5 * 1024 * 1024,\n];\n";
file_put_contents(TestServer::projectRoot() . '/config.php', $config);

echo "MySQL test database '{$name}' imported and config.php written.\n";
