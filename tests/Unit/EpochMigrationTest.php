<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Task #108: the one-time datetime -> epoch conversion of a pre-#108 board.
 * Fresh installs get BIGINT columns from schema.sql; existing boards are
 * converted in place by tb_migrate_epoch_columns() on the first request: the
 * legacy string values (written by the database clock) become epoch seconds,
 * the columns become integers, and nothing else about the row changes. The
 * legacy values here are UTC strings (what SQLite's datetime('now') writes
 * and what a MySQL server pinned to UTC writes); a host that ran a different
 * server timezone sets the legacy_db_timezone config offset instead.
 */
final class EpochMigrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbFile = '';

    protected function setUp(): void
    {
        unset($GLOBALS['TB_CONFIG']);
        $this->dbFile = sys_get_temp_dir() . '/tb-epoch-' . getmypid() . '-' . random_int(1000, 9999) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, last_seen_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, archived_at TEXT NULL)');
        $this->pdo->exec("INSERT INTO users(id, created_at, last_seen_at) VALUES(1, '2026-11-23 06:45:18', NULL)");
        $this->pdo->exec("INSERT INTO tasks(id, created_at, archived_at) VALUES(7, '2026-11-23 06:45:18', '2026-11-23 07:45:18')");
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
        @unlink($this->dbFile);
    }

    public function test_legacy_string_stamps_convert_to_epoch_and_the_columns_retype(): void
    {
        $this->pdo->exec("INSERT INTO tasks(id, created_at, archived_at) VALUES(9, '2026-11-24 00:00:00', NULL)");

        tb_migrate_epoch_columns($this->pdo);

        $row = $this->pdo->query('SELECT created_at, last_seen_at FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((string)strtotime('2026-11-23 06:45:18'), (string)$row['created_at'], 'The created stamp converts to its epoch second.');
        $this->assertNull($row['last_seen_at'], 'A never-seen stamp stays NULL.');

        $task = $this->pdo->query('SELECT created_at, archived_at FROM tasks WHERE id=7')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((string)strtotime('2026-11-23 06:45:18'), (string)$task['created_at']);
        $this->assertSame((string)strtotime('2026-11-23 07:45:18'), (string)$task['archived_at'], 'A set archived stamp converts too.');

        $late = $this->pdo->query('SELECT created_at FROM tasks WHERE id=9')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((string)strtotime('2026-11-24 00:00:00'), (string)$late['created_at']);

        // The conversion is idempotent: a second pass changes nothing.
        $before = $this->pdo->query('SELECT created_at FROM tasks WHERE id=7')->fetchColumn();
        tb_migrate_epoch_columns($this->pdo);
        $this->assertSame($before, $this->pdo->query('SELECT created_at FROM tasks WHERE id=7')->fetchColumn(), 'Numeric stamps pass through untouched.');
    }

    public function test_sqlite_reads_its_strings_as_utc_regardless_of_the_knob(): void
    {
        // SQLite strings are always UTC (datetime('now') has no timezone
        // argument), so the legacy_db_timezone offset - a MySQL-side knob for
        // hosts whose server timezone changed - does not apply here and the
        // conversion reads every string as UTC.
        $GLOBALS['TB_CONFIG'] = ['legacy_db_timezone' => '+02:00'];

        tb_migrate_epoch_columns($this->pdo);

        $row = $this->pdo->query('SELECT created_at FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((string)strtotime('2026-11-23 06:45:18'), (string)$row['created_at'], 'SQLite strings convert as UTC.');
    }

    public function test_new_style_numeric_columns_are_left_alone(): void
    {
        // A fresh #108 board (or an already-converted one): the columns are
        // integers and the conversion is a no-op that must not touch values.
        $this->pdo->exec('ALTER TABLE tasks RENAME TO tasks_legacy');
        $this->pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at BIGINT NOT NULL DEFAULT 0, archived_at BIGINT NULL)');
        $this->pdo->exec('INSERT INTO tasks(id, created_at, archived_at) VALUES(3, 1753271136, NULL)');

        tb_migrate_epoch_columns($this->pdo);

        $row = $this->pdo->query('SELECT created_at, archived_at FROM tasks WHERE id=3')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1753271136', (string)$row['created_at'], 'An integer stamp is untouched.');
        $this->assertNull($row['archived_at']);
    }
}
