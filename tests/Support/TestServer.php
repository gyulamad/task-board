<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Support;

use PDO;
use RuntimeException;

/**
 * Boots the task board application in a PHP built-in web server for tests.
 *
 * TEST_DB_MODE=sqlite (default):
 *   Copies index.php / updater.php / src/ / uploads/.htaccess into a temp
 *   directory, applies two strictly documented SQLite compatibility edits to
 *   the copies (the PDO DSN and INSERT IGNORE -> INSERT OR IGNORE; SQLite has
 *   no INSERT IGNORE), writes a temp config.php and creates the database from
 *   schema.sql via Schema::toSqlite(). Production files are never modified.
 *
 * TEST_DB_MODE=mysql:
 *   Runs the verbatim project files from the project root against a real
 *   MySQL/MariaDB server. tests/bin/prepare-mysql.php must have written
 *   config.php from the TEST_DB_* environment variables first.
 *
 * TEST_BASE_URL (optional): connect to an already running server instead of
 * booting one (used by CI so coverage maps to the checked out files).
 */
final class TestServer
{
    private static ?string $url = null;
    private static ?string $docRoot = null;
    private static ?string $dbFile = null;
    private static $process = null;
    private static ?string $logFile = null;
    private static ?PDO $pdo = null;

    public static function dbMode(): string
    {
        $mode = getenv('TEST_DB_MODE') ?: 'sqlite';
        if (!in_array($mode, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('TEST_DB_MODE must be sqlite or mysql.');
        }
        return $mode;
    }

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function url(): string
    {
        self::ensureRunning();
        return self::$url;
    }

    /** The document root the application server is serving (temp copy for SQLite mode). */
    public static function docRoot(): string
    {
        self::ensureRunning();
        if (self::$docRoot === null) {
            throw new RuntimeException('The test server document root is not known yet.');
        }
        return self::$docRoot;
    }

    public static function ensureRunning(): void
    {
        if (self::$url !== null) {
            return;
        }
        $external = getenv('TEST_BASE_URL');
        if ($external) {
            self::$url = rtrim($external, '/');
            self::$docRoot = self::projectRoot();
            return;
        }
        if (self::dbMode() !== 'sqlite') {
            throw new RuntimeException('TEST_DB_MODE=mysql requires TEST_BASE_URL; boot the server via tests/bin/prepare-mysql.php + php -S.');
        }
        self::bootSqlite();
    }

    private static function bootSqlite(): void
    {
        $root = self::projectRoot();
        $dir = sys_get_temp_dir() . '/task-board-test-' . getmypid();
        if (is_dir($dir)) {
            self::rrmdir($dir);
        }
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/uploads', 0777, true);
        foreach (['index.php', 'updater.php'] as $file) {
            copy($root . '/' . $file, $dir . '/' . $file);
        }
        copy($root . '/src/helpers.php', $dir . '/src/helpers.php');
        if (is_file($root . '/uploads/.htaccess')) {
            copy($root . '/uploads/.htaccess', $dir . '/uploads/.htaccess');
        }

        $index = (string)file_get_contents($dir . '/index.php');
        $dsn = '"mysql:host={$config[\'db_host\']};dbname={$config[\'db_name\']};charset=utf8mb4"';
        if (!str_contains($index, $dsn)) {
            throw new RuntimeException('Could not find the MySQL DSN in the copied index.php; the SQLite compatibility patch failed.');
        }
        $index = str_replace($dsn, '"sqlite:" . __DIR__ . \'/board.sqlite\'', $index);
        $index = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $index);
        // SQLite does not enforce foreign keys unless the connection opts in;
        // production MySQL (InnoDB) enforces them by default, so enable the
        // pragma to keep the same CASCADE / SET NULL semantics in tests.
        $options = "[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]\n    );";
        if (!str_contains($index, $options)) {
            throw new RuntimeException('Could not find the PDO options in the copied index.php; the SQLite compatibility patch failed.');
        }
        $index = str_replace($options, $options . "\n    \$pdo->exec('PRAGMA foreign_keys=ON');", $index);
        file_put_contents($dir . '/index.php', $index);

        self::$dbFile = $dir . '/board.sqlite';
        file_put_contents($dir . '/config.php', "<?php return ['db_host'=>'', 'db_name'=>'', 'db_user'=>'', 'db_pass'=>'', 'app_name'=>'Task Board Test', 'max_upload_bytes'=>5*1024*1024];\n");

        self::resetDatabase();
        self::startServer($dir);
    }

    /** Drops and recreates the application database with an empty schema.sql structure. */
    public static function resetDatabase(): void
    {
        self::$pdo = null;
        if (self::dbMode() === 'sqlite') {
            if (self::$dbFile === null) {
                // Called from the CLI prepare script before boot: resolve the temp dir path.
                self::$dbFile = sys_get_temp_dir() . '/task-board-test-' . getmypid() . '/board.sqlite';
                if (!is_dir(dirname(self::$dbFile))) {
                    mkdir(dirname(self::$dbFile), 0777, true);
                }
            }
            Schema::createSqlite(self::$dbFile);
            return;
        }
        $name = getenv('TEST_DB_NAME') ?: 'task_board_test';
        Schema::importMysql(
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            $name,
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASS') ?: ''
        );
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (self::dbMode() === 'sqlite') {
                if (self::$dbFile === null) {
                    self::$dbFile = sys_get_temp_dir() . '/task-board-test-' . getmypid() . '/board.sqlite';
                }
                self::$pdo = new PDO('sqlite:' . self::$dbFile);
                self::$pdo->exec('PRAGMA foreign_keys=ON');
            } else {
                $name = getenv('TEST_DB_NAME') ?: 'task_board_test';
                self::$pdo = new PDO(
                    "mysql:host=" . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ";dbname={$name};charset=utf8mb4",
                    getenv('TEST_DB_USER') ?: 'root',
                    getenv('TEST_DB_PASS') ?: '',
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            }
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$pdo;
    }

    public static function log(): string
    {
        return self::$logFile ?? '(server not started)';
    }

    private static function startServer(string $docRoot): void
    {
        self::$logFile = sys_get_temp_dir() . '/task-board-test-server-' . getmypid() . '.log';
        $basePort = 20000 + (int)(getmypid() % 5000) * 8;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = $basePort + $attempt;
            $descriptors = [1 => ['file', self::$logFile, 'a'], 2 => ['file', self::$logFile, 'a']];
            self::$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot], $descriptors, $pipes);
            if (!is_resource(self::$process)) {
                continue;
            }
            self::$docRoot = $docRoot;
            $candidate = 'http://127.0.0.1:' . $port;
            for ($i = 0; $i < 40; $i++) {
                $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
                $body = @file_get_contents($candidate . '/?page=login', false, $ctx);
                if ($body !== false) {
                    self::$url = $candidate;
                    return;
                }
                usleep(250000);
            }
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        throw new RuntimeException('Could not start the PHP built-in web server. Log: ' . (self::$logFile ?? ''));
    }

    public static function shutdown(): void
    {
        if (self::$process !== null && is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        if (self::$docRoot !== null && str_starts_with(self::$docRoot, sys_get_temp_dir())) {
            self::rrmdir(self::$docRoot);
        }
    }

    private static function rrmdir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
