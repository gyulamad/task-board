<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;
use ZipArchive;

/**
 * Task #69 - the upgrade pack flow on the Software updates page: a pack zip
 * with pack.json (previous_version / new_version), files/, deletions.json and
 * migration.sql is accepted only when its previous_version matches the
 * recorded instance hash, then applies files, deletions and the migration
 * (through the #68 engine) and records the new hash in version.json.
 */
final class UpgradePackTest extends E2eTestCase
{
    private string $docRoot;
    private string $migrationDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docRoot = TestServer::docRoot();
        $this->migrationDir = $this->docRoot . '/migrations';
        if (!is_dir($this->migrationDir)) mkdir($this->migrationDir, 0775, true);
        foreach (glob($this->migrationDir . '/*.sql') ?: [] as $file) @unlink((string)$file);
        TestServer::pdo()->exec('DELETE FROM migrations');
        file_put_contents($this->docRoot . '/version.json', "{\"source\":\"branch\",\"ref\":\"test\",\"sha\":\"testhash0001\",\"installed_at\":\"now\"}\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationDir . '/*') ?: [] as $file) @unlink((string)$file);
        if (is_dir($this->migrationDir)) @rmdir($this->migrationDir);
        foreach (['version.json', 'pack_probe.php', 'pack_gone.php'] as $file) {
            if (is_file($this->docRoot . '/' . $file)) @unlink($this->docRoot . '/' . $file);
        }
        parent::tearDown();
    }

    /** Builds an upgrade pack ZIP with the given overrides. */
    private function buildPack(array $overrides = [], string $prefix = ''): string
    {
        $entries = array_merge([
            'pack.json' => json_encode(['previous_version' => 'testhash0001', 'new_version' => 'testhash0002']),
            'files/pack_probe.php' => '<?php // pack probe file',
            'deletions.json' => json_encode(['pack_gone.php']),
            'migration.sql' => 'CREATE TABLE e2e_pack_probe(id INTEGER);',
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'tb-e2e-pack-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            if ($content === null) continue;
            $zip->addFromString($prefix === '' ? $name : $prefix . '/' . $name, (string)$content);
        }
        $zip->close();
        return (string)$path;
    }

    private function postPack(string $zipPath, string $password = 'AdminPass1234')
    {
        return $this->adminSession()->upload('/?page=updates', [
            'action' => 'apply_pack',
            'current_password' => $password,
        ], 'pack_zip', $zipPath, 'upgrade-pack.zip', 'application/zip');
    }

    /** The version stamps recorded in the migrations table (#68 engine). */
    private function recordedVersions(): array
    {
        $s = TestServer::pdo()->query('SELECT version FROM migrations ORDER BY version');
        $rows = $s->fetchAll(\PDO::FETCH_COLUMN);
        $s->closeCursor();
        return array_map(fn(string $v): string => (string)$v, $rows);
    }

    /** Whether the named table exists in the running test database (#172). */
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

    public function test_updates_page_offers_the_pack_flow(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=updates');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Apply an upgrade pack'), 'the page must present the pack section.');
        $this->assertTrue($page->contains('name="action" value="apply_pack"'), 'the pack form must POST the apply_pack action.');
        $this->assertTrue($page->contains('testhash0001'), 'the page must show the recorded version hash.');
    }

    public function test_pack_applies_files_deletions_migration_and_version(): void
    {
        file_put_contents($this->docRoot . '/pack_gone.php', '<?php // to be deleted');
        $zipPath = $this->buildPack();

        $response = $this->postPack($zipPath);
        unlink($zipPath);

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Upgrade pack applied'), 'the page must confirm the pack.');
        $this->assertTrue($response->contains('1 file written'), 'files/ entries land on the instance.');
        $this->assertTrue($response->contains('1 file deleted'), 'deletions.json entries are removed.');
        $this->assertFileExists($this->docRoot . '/pack_probe.php');
        $this->assertFileDoesNotExist($this->docRoot . '/pack_gone.php');
        $stored = glob($this->migrationDir . '/*-upgrade_pack.sql') ?: [];
        $this->assertCount(1, $stored, 'the pack migration is stored version-marked in migrations/.');
        $this->assertSame([substr(basename((string)$stored[0]), 0, 14)], $this->recordedVersions(), 'the #68 engine recorded the pack migration.');
        // #172 fix: the probe ran a raw sqlite_master query, which does not
        // exist on MySQL - the file already carries the dialect-branched
        // tableExists() helper, so the assertion rides it.
        $this->assertTrue($this->tableExists('e2e_pack_probe'), 'the pack migration ran.');
        $installed = json_decode((string)file_get_contents($this->docRoot . '/version.json'), true);
        $this->assertSame('testhash0002', $installed['sha'], 'the instance records the pack new_version.');
        $this->assertSame('upgrade-pack', $installed['source']);

        // A second pack built for the old hash no longer fits.
        $stale = $this->buildPack();
        $second = $this->postPack($stale);
        unlink($stale);
        $this->assertTrue($second->contains('does not fit'), 'a pack for the old hash must be rejected.');
    }

    public function test_pack_rejects_a_wrong_hash_without_touching_the_instance(): void
    {
        file_put_contents($this->docRoot . '/pack_gone.php', 'keep me');
        $zipPath = $this->buildPack(['pack.json' => json_encode(['previous_version' => 'anotherhash9999', 'new_version' => 'testhash0002'])]);

        $response = $this->postPack($zipPath);
        unlink($zipPath);

        $this->assertTrue($response->contains('does not fit'));
        $this->assertFileDoesNotExist($this->docRoot . '/pack_probe.php');
        $this->assertFileExists($this->docRoot . '/pack_gone.php');
        $this->assertSame('testhash0001', json_decode((string)file_get_contents($this->docRoot . '/version.json'), true)['sha']);
        $this->assertSame([], $this->recordedVersions(), 'nothing is recorded for a rejected pack.');
    }

    public function test_pack_rejects_a_wrong_password(): void
    {
        $zipPath = $this->buildPack();
        $response = $this->postPack($zipPath, 'NotTheAdminPassword1');
        unlink($zipPath);
        $this->assertTrue($response->contains('Your current administrator password was not accepted.'));
        $this->assertFileDoesNotExist($this->docRoot . '/pack_probe.php');
    }

    public function test_pack_requires_a_valid_csrf_token(): void
    {
        $admin = $this->adminSession();
        $response = $admin->postWithoutCsrf('/?page=updates', ['action' => 'apply_pack']);
        $this->assertSame(403, $response->status);
        $this->assertTrue($response->contains('Invalid CSRF token.'));
    }

    public function test_pack_reports_a_broken_zip_and_a_missing_instance_hash(): void
    {
        $admin = $this->adminSession();
        $garbage = tempnam(sys_get_temp_dir(), 'tb-e2e-pack-');
        file_put_contents($garbage, 'definitely not a zip archive');
        $bad = $this->postPack($garbage);
        unlink($garbage);
        $this->assertTrue($bad->contains('not a valid ZIP archive'));

        unlink($this->docRoot . '/version.json');
        $zipPath = $this->buildPack();
        $noVersion = $this->postPack($zipPath);
        unlink($zipPath);
        $this->assertTrue($noVersion->contains('no recorded version hash'), 'an instance without version.json must be told why the pack refuses.');
    }
}
