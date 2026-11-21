<?php
declare(strict_types=1);

require __DIR__ . '/Support/HttpResponse.php';
require __DIR__ . '/Support/HttpClient.php';
require __DIR__ . '/Support/Schema.php';
require __DIR__ . '/Support/TestServer.php';
require __DIR__ . '/Support/AppSession.php';
require __DIR__ . '/E2E/E2eTestCase.php';

date_default_timezone_set('UTC');
error_reporting(E_ALL);

// Always stop the application server and clean up the temp deployment when
// the PHPUnit process ends. Without this the spawned child would outlive the
// runner and keep inherited file descriptors open.
register_shutdown_function(['TaskBoard\Tests\Support\TestServer', 'shutdown']);
