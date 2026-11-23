<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Version-marked SQL migrations (#59/#68): pending migrations/*.sql files
 * apply automatically on the first request that sees them, exactly once,
 * and the Software updates page can upload and apply a script immediately.
 */
final class MigrationTest extends E2eTestCase
{
    private string $migrationDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationDir = TestServer::docRoot() . '/migrations';
        if (!is_dir($this->migrationDir)) {
            mkdir($this->migrationDir, 0777, true);
        }
        // Tests own the folder for their duration: leftover fixtures from an
        // earlier test in the same process must not leak into this one.
        foreach (glob($this->migrationDir . '/*.sql') ?: [] as $leftover) {
            @unlink($leftover);
        }
        TestServer::pdo()->exec('DELETE FROM migrations');
    }

    /** A probe table DDL in the dialect of the running test database. */
    private function probeTableSql(string $table): string
    {
        if (TestServer::dbMode() === 'mysql') {
            return "CREATE TABLE {$table} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note VARCHAR(80) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }
        return "CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT NOT NULL)";
    }

    private function probeRowCount(string $table): int
    {
        return (int)TestServer::pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    /** Whether the named table exists in the running test database. */
    private function tableExists(string $table): bool
    {
        if (TestServer::dbMode() === 'mysql') {
            return (bool)TestServer::pdo()->query("SHOW TABLES LIKE '" . $table . "'")->fetchColumn();
        }
        $s = TestServer::pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $s->execute([$table]);
        $found = (bool)$s->fetchColumn();
        $s->closeCursor();
        return $found;
    }

    public function test_a_pending_migration_file_applies_once_automatically(): void
    {
        $admin = $this->adminSession();
        file_put_contents($this->migrationDir . '/20260101120000-create-migration-probe.sql',
            $this->probeTableSql('migration_probe') . ";\nINSERT INTO migration_probe (note) VALUES ('hello');\n-- a trailing comment must not become a statement\n");

        // Any request runs the bootstrap: the pending migration applies there.
        $this->assertSame(200, $admin->get('/')->status);
        $this->assertSame(1, $this->probeRowCount('migration_probe'), 'The migration applied on the request.');
        $version = TestServer::pdo()->query("SELECT COUNT(*) FROM migrations WHERE version='20260101120000'")->fetchColumn();
        $this->assertSame('1', (string)$version, 'The applied version is recorded.');

        // A second request must not re-run it (the INSERT would duplicate the row).
        $this->assertSame(200, $admin->get('/')->status);
        $this->assertSame(1, $this->probeRowCount('migration_probe'), 'The recorded migration never runs again.');
    }

    public function test_migrations_apply_in_version_order_and_stop_at_a_failure(): void
    {
        $admin = $this->adminSession();
        // The first file creates the table, the second inserts a row, the
        // third references a column the first never made and fails: the run
        // stops there and the third version stays unrecorded.
        file_put_contents($this->migrationDir . '/20260101120000-create-migration-probe.sql', $this->probeTableSql('migration_probe') . ";\n");
        file_put_contents($this->migrationDir . '/20260101120100-insert-migration-probe.sql', "INSERT INTO migration_probe (note) VALUES ('second');\n");
        file_put_contents($this->migrationDir . '/20260101120200-break-migration-probe.sql', "INSERT INTO migration_probe (missing_column) VALUES ('third');\n");

        $this->assertSame(200, $admin->get('/')->status);
        $this->assertSame(1, $this->probeRowCount('migration_probe'), 'The first two migrations applied in order.');
        $rows = TestServer::pdo()->query('SELECT version FROM migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['20260101120000', '20260101120100'], $rows, 'The failed version is not recorded; earlier ones are.');
    }

    public function test_the_updates_page_upload_applies_a_sql_migration_immediately(): void
    {
        $admin = $this->adminSession();
        $file = tempnam(sys_get_temp_dir(), 'migration-');
        file_put_contents($file, $this->probeTableSql('migration_upload_probe') . ";\nINSERT INTO migration_upload_probe (note) VALUES ('uploaded');\n");

        $response = $admin->upload('/?page=updates', [
            'action' => 'upload_migration',
            'current_password' => 'AdminPass1234',
        ], 'migration_file', $file, 'add-migration-upload-probe.sql', 'application/sql');
        unlink($file);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('uploaded and applied'), 'The page confirms the migration.');

        // The stored file is version-marked and the upload probe applied.
        $stored = glob($this->migrationDir . '/*-add-migration-upload-probe.sql') ?: [];
        $this->assertCount(1, $stored, 'The upload is stored in migrations/ with a version stamp.');
        $this->assertSame(1, $this->probeRowCount('migration_upload_probe'), 'The uploaded SQL applied immediately.');
        $version = substr(basename($stored[0], '.sql'), 0, 14);
        $this->assertMatchesRegularExpression('/^\d{14}$/', $version, 'The stored name starts with a bare 14-digit stamp.');
        $recorded = TestServer::pdo()->prepare('SELECT COUNT(*) FROM migrations WHERE version=?');
        $recorded->execute([$version]);
        $this->assertSame('1', (string)$recorded->fetchColumn(), 'The uploaded version is recorded.');
        // Drain the direct PDO statement before the app's next write (see #61 v3).
        $recorded->closeCursor();
    }

    public function test_a_wrong_admin_password_cannot_upload_a_migration(): void
    {
        $admin = $this->adminSession();
        $file = tempnam(sys_get_temp_dir(), 'migration-');
        file_put_contents($file, $this->probeTableSql('migration_wrong_password') . ";\n");

        $response = $admin->upload('/?page=updates', [
            'action' => 'upload_migration',
            'current_password' => 'not-the-password',
        ], 'migration_file', $file, 'add-wrong-password.sql', 'application/sql');
        unlink($file);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('password was not accepted'));
        $this->assertSame([], glob($this->migrationDir . '/*-add-wrong-password.sql') ?: [], 'Nothing is stored without the password check.');
        $this->assertFalse($this->tableExists('migration_wrong_password'), 'The migration never ran.');
    }

    public function test_non_sql_uploads_are_rejected(): void
    {
        $admin = $this->adminSession();
        $file = tempnam(sys_get_temp_dir(), 'migration-');
        file_put_contents($file, '<?php echo 1;');

        $response = $admin->upload('/?page=updates', [
            'action' => 'upload_migration',
            'current_password' => 'AdminPass1234',
        ], 'migration_file', $file, 'payload.php', 'application/x-php');
        unlink($file);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Only .sql files are accepted.'));
    }

    public function test_a_failed_sql_upload_is_rejected_and_the_board_stays_healthy(): void
    {
        $admin = $this->adminSession();
        $file = tempnam(sys_get_temp_dir(), 'migration-');
        file_put_contents($file, "CREATE TABLE migration_broken_probe (\n");

        $response = $admin->upload('/?page=updates', [
            'action' => 'upload_migration',
            'current_password' => 'AdminPass1234',
        ], 'migration_file', $file, 'broken-migration.sql', 'application/sql');
        unlink($file);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('failed to apply and was not kept'), 'The driver error is reported.');
        $this->assertSame([], glob($this->migrationDir . '/*-broken-migration.sql') ?: [], 'A rejected script is not kept (it would retry on every request).');

        // The board is healthy: the failed upload never broke the bootstrap.
        $this->assertSame(200, $admin->get('/')->status);
        $this->assertSame(200, $admin->taskPage($admin->createTask('Still works'))->status);
    }

    public function test_non_admins_cannot_upload_migrations(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'worker',
            'display_name' => 'Worker',
            'password' => 'WorkerPass1234',
            'role' => 'user',
        ]);
        $worker = new AppSession('worker', 'WorkerPass1234');
        $response = $worker->get('/?page=updates');
        $this->assertSame(403, $response->status, 'The updates page is admin-only, migrations ride on it.');
    }
}
