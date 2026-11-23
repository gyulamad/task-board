<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

// updater.php refuses to run outside the application boot (404 "Not found."),
// so the unit test declares the boot constant before loading the engine.
if (!defined('TASK_BOARD_BOOTSTRAPPED')) define('TASK_BOARD_BOOTSTRAPPED', true);
require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/updater.php';

/**
 * Task #69 - the upgrade pack engine: path guard, pack.json metadata, layout
 * handling (root and wrapped archives), the previous-version-hash acceptance
 * check and the apply semantics (files, deletions, migration through the #68
 * engine, version.json update, rollback), exercised against a temporary
 * application directory and an in-memory SQLite database. These run without
 * any server.
 */
final class UpgradePackTest extends TestCase
{
    private string $appDir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->appDir = sys_get_temp_dir() . '/tb-pack-app-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!is_dir($this->appDir)) mkdir($this->appDir, 0777, true);
        file_put_contents($this->appDir . '/version.json', "{\"source\":\"branch\",\"sha\":\"basehash0001\"}\n");
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The #68 engine tracks applied scripts in the migrations table; the
        // app self-heals it on real requests, a bare unit-test connection
        // creates it here with the schema.sql DDL.
        $this->pdo->exec('CREATE TABLE migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version TEXT NOT NULL UNIQUE,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->appDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir((string)$file) : @unlink((string)$file);
        }
        @rmdir($this->appDir);
    }

    /** Builds an upgrade pack ZIP from [entry name => content]. */
    private function buildZip(array $entries, string $prefix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tb-packzip-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($prefix === '' ? $name : $prefix . '/' . $name, $content);
        }
        $zip->close();
        return (string)$path;
    }

    private function packJson(string $previous = 'basehash0001', string $new = 'newhash0002'): string
    {
        return json_encode(['previous_version' => $previous, 'new_version' => $new]);
    }

    public function test_path_guard(): void
    {
        $this->assertTrue(tb_pack_path_is_allowed('src/helpers_extra.php'));
        $this->assertTrue(tb_pack_path_is_allowed('README.md'));
        $this->assertTrue(tb_pack_path_is_allowed('deep/nested/file.php'));
        $this->assertTrue(tb_pack_path_is_allowed('migrations/20260101120000-shipped.sql'), 'a pack may ship migration scripts');
        $this->assertFalse(tb_pack_path_is_allowed('config.php'), 'credentials stay untouchable');
        $this->assertFalse(tb_pack_path_is_allowed('index.php'), 'the boot files stay untouchable');
        $this->assertFalse(tb_pack_path_is_allowed('updater.php'));
        $this->assertFalse(tb_pack_path_is_allowed('src/helpers.php'));
        $this->assertFalse(tb_pack_path_is_allowed('uploads/a.zip'), 'uploaded files stay untouchable');
        $this->assertFalse(tb_pack_path_is_allowed('uploads'));
        $this->assertFalse(tb_pack_path_is_allowed('../outside.php'), 'no traversal');
        $this->assertFalse(tb_pack_path_is_allowed('a/../../outside.php'), 'no traversal in the middle');
        $this->assertFalse(tb_pack_path_is_allowed('/absolute.php'), 'no absolute paths');
        $this->assertFalse(tb_pack_path_is_allowed('back\\slash.php'), 'backslashes are not separators');
        $this->assertFalse(tb_pack_path_is_allowed(''));
    }

    public function test_current_version_hash_reads_version_json(): void
    {
        $this->assertSame('basehash0001', tb_pack_current_version_hash($this->appDir));
        file_put_contents($this->appDir . '/version.json', '{"source":"branch"}');
        $this->assertNull(tb_pack_current_version_hash($this->appDir), 'an empty sha is "not recorded"');
        unlink($this->appDir . '/version.json');
        $this->assertNull(tb_pack_current_version_hash($this->appDir));
    }

    public function test_pack_read_parses_the_root_layout(): void
    {
        $zipPath = $this->buildZip([
            'pack.json' => $this->packJson(),
            'files/new.php' => '<?php echo 1;',
            'files/deep/old.php' => 'old body',
            'deletions.json' => json_encode(['gone.php']),
            'migration.sql' => 'CREATE TABLE pack_probe(id INTEGER);',
            'unknown.txt' => 'ignored',
        ]);
        $pack = tb_pack_read((string)file_get_contents($zipPath));
        unlink($zipPath);
        $this->assertSame('basehash0001', $pack['previous_version']);
        $this->assertSame('newhash0002', $pack['new_version']);
        $this->assertSame(['new.php' => '<?php echo 1;', 'deep/old.php' => 'old body'], $pack['files']);
        $this->assertSame(['gone.php'], $pack['deletions']);
        $this->assertSame('CREATE TABLE pack_probe(id INTEGER);', $pack['migration']);
    }

    public function test_pack_read_parses_a_wrapped_archive(): void
    {
        $zipPath = $this->buildZip([
            'pack.json' => $this->packJson(),
            'files/new.php' => 'body',
        ], 'task-board-abc12');
        $pack = tb_pack_read((string)file_get_contents($zipPath));
        unlink($zipPath);
        $this->assertSame('basehash0001', $pack['previous_version']);
        $this->assertSame(['new.php' => 'body'], $pack['files']);
    }

    public function test_pack_read_rejects_broken_packs(): void
    {
        $expect = function (string $needle, array $entries, string $prefix = ''): void {
            $zipPath = $this->buildZip($entries, $prefix);
            try {
                tb_pack_read((string)file_get_contents($zipPath));
                $this->fail('Expected the pack to be rejected with: ' . $needle);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            } finally {
                unlink($zipPath);
            }
        };
        $expect('missing its pack.json', ['files/x.php' => 'body']);
        $expect('not valid JSON', ['pack.json' => '{broken']);
        $expect('previous_version and a new_version', ['pack.json' => json_encode(['previous_version' => 'only one'])]);
        $expect('unsafe path', ['pack.json' => $this->packJson(), 'x/../y.php' => 'body']);
        $expect('unexpected directory layout', ['tb/pack.json' => $this->packJson(), 'stray.txt' => 'loose entry beside the wrapper']);
        $expect('outside the allowed application paths', ['pack.json' => $this->packJson(), 'files/config.php' => 'hijack']);
        $expect('reserved by the pack format', ['pack.json' => $this->packJson(), 'files/pack.json' => 'confusing']);
        $expect('pack is empty', ['pack.json' => $this->packJson()]);
        $expect('not valid JSON', ['pack.json' => $this->packJson(), 'deletions.json' => 'no json']);
        $expect('outside the allowed application paths', ['pack.json' => $this->packJson(), 'deletions.json' => json_encode(['uploads/secret.zip'])]);
    }

    public function test_pack_read_rejects_an_invalid_zip(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid ZIP archive');
        tb_pack_read('this is not a zip file at all');
    }

    public function test_pack_apply_writes_deletes_migrates_and_records_the_version(): void
    {
        file_put_contents($this->appDir . '/existing.php', 'old body');
        file_put_contents($this->appDir . '/gone.php', 'delete me');
        $pack = [
            'previous_version' => 'basehash0001',
            'new_version' => 'newhash0002',
            'files' => ['existing.php' => 'new body', 'src/added.php' => 'added body'],
            'deletions' => ['gone.php'],
            'migration' => 'CREATE TABLE pack_probe(id INTEGER);',
        ];
        $report = tb_pack_apply($this->pdo, $pack, $this->appDir);
        $this->assertSame(['existing.php', 'src/added.php'], $report['files_changed']);
        $this->assertSame(['gone.php'], $report['files_deleted']);
        $this->assertMatchesRegularExpression('/^\d{14}-upgrade_pack\.sql$/', basename((string)$report['migration_file']), 'the pack migration is stored version-marked per the #68 convention');
        $this->assertSame('new body', (string)file_get_contents($this->appDir . '/existing.php'));
        $this->assertSame('added body', (string)file_get_contents($this->appDir . '/src/added.php'));
        $this->assertFileDoesNotExist($this->appDir . '/gone.php');
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pack_probe'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['pack_probe'], $tables, 'the pack migration ran through the #68 engine');
        $rows = $this->pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([substr(basename((string)$report['migration_file']), 0, 14)], $rows, 'the migration is recorded once under its version stamp');
        $installed = json_decode((string)file_get_contents($this->appDir . '/version.json'), true);
        $this->assertSame('newhash0002', $installed['sha'], 'the pack records the new version hash');
        $this->assertSame('upgrade-pack', $installed['source']);
        // A second pack now verifies against the new hash: the old one no longer fits.
        $stale = $pack;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not fit');
        tb_pack_apply($this->pdo, $stale, $this->appDir);
    }

    public function test_pack_apply_rejects_a_version_without_recorded_hash(): void
    {
        unlink($this->appDir . '/version.json');
        $pack = ['previous_version' => 'basehash0001', 'new_version' => 'newhash0002', 'files' => ['a.php' => 'x'], 'deletions' => [], 'migration' => null];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no recorded version hash');
        tb_pack_apply($this->pdo, $pack, $this->appDir);
    }

    public function test_pack_apply_refuses_protected_paths_even_from_a_hand_built_pack(): void
    {
        $pack = ['previous_version' => 'basehash0001', 'new_version' => 'newhash0002', 'files' => ['config.php' => 'hijack'], 'deletions' => [], 'migration' => null];
        try {
            tb_pack_apply($this->pdo, $pack, $this->appDir);
            $this->fail('Expected the protected path to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside the allowed application paths', $e->getMessage());
        }
        $this->assertStringEqualsFile($this->appDir . '/version.json', "{\"source\":\"branch\",\"sha\":\"basehash0001\"}\n");
    }

    public function test_pack_apply_rolls_files_back_when_the_migration_fails(): void
    {
        file_put_contents($this->appDir . '/existing.php', 'old body');
        $pack = [
            'previous_version' => 'basehash0001',
            'new_version' => 'newhash0002',
            'files' => ['existing.php' => 'new body'],
            'deletions' => [],
            'migration' => 'CREATE TABLE pack_probe(id INTEGER); CREATE TABLE pack_probe(id INTEGER);',
        ];
        try {
            tb_pack_apply($this->pdo, $pack, $this->appDir);
            $this->fail('Expected the failing migration to fail the pack.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }
        $this->assertStringEqualsFile($this->appDir . '/existing.php', 'old body', 'the file change is rolled back');
        $this->assertSame('basehash0001', json_decode((string)file_get_contents($this->appDir . '/version.json'), true)['sha'], 'the version hash stays at the old one');
        $this->assertSame([], $this->pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN), 'nothing is recorded for a failed pack');
        $this->assertSame([], glob($this->appDir . '/migrations/*.sql') ?: [], 'a stored migration that never applied is removed, it must not auto-apply later');
    }

    public function test_pack_result_text_summarizes_the_report(): void
    {
        $text = tb_pack_result_text([
            'files_changed' => ['a.php'],
            'files_deleted' => ['b.php', 'c.php'],
            'migration_file' => $this->appDir . '/migrations/20260101120000-upgrade_pack.sql',
            'migrations' => ['applied' => ['20260101120000'], 'failed' => []],
            'new_version' => 'newhash0002',
        ]);
        $this->assertStringContainsString('1 file written (a.php)', $text);
        $this->assertStringContainsString('2 files deleted (b.php, c.php)', $text);
        $this->assertStringContainsString('pack migration stored as 20260101120000-upgrade_pack.sql', $text);
        $this->assertStringContainsString('migrations applied: 20260101120000', $text);
        $this->assertStringContainsString('instance is now at version "newhash0002"', $text);

        $plain = tb_pack_result_text([
            'files_changed' => [],
            'files_deleted' => [],
            'migration_file' => null,
            'migrations' => ['applied' => [], 'failed' => []],
            'new_version' => 'v2',
        ]);
        $this->assertStringContainsString('no pending migrations were found', $plain);
    }
}
