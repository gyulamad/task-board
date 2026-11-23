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
 * Task #70 - the upgrade pack generator: the running-tree scan with its
 * exclusions, the base-tree extraction from a GitHub-style archive, the
 * diff classification and the generated pack itself - which must re-parse
 * and apply through the #69 engine so another instance really can upgrade
 * to this tree without GitHub.
 */
final class PackGeneratorTest extends TestCase
{
    private string $appDir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->appDir = sys_get_temp_dir() . '/tb-packgen-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!is_dir($this->appDir)) mkdir($this->appDir, 0777, true);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    /** Writes a file into the generator application directory. */
    private function put(string $relative, string $content): void
    {
        $path = $this->appDir . '/' . $relative;
        $parent = dirname($path);
        if (!is_dir($parent)) mkdir($parent, 0777, true);
        file_put_contents($path, $content);
    }

    /** Builds a GitHub-style base archive (one top directory) from [relative => content]. */
    private function baseZip(array $tree): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tb-genbase-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($tree as $relative => $content) {
            $zip->addFromString('task-board-abc12/' . $relative, $content);
        }
        $zip->close();
        return (string)$path;
    }

    public function test_tree_scan_excludes_instance_files(): void
    {
        $this->put('index.php', '<?php echo 1;');
        $this->put('config.php', '<?php return ["secret"];');
        $this->put('version.json', '{"sha":"x"}');
        $this->put('pack.json', '{}');
        $this->put('board.sqlite', 'not code');
        $this->put('backup.zip', 'not code');
        $this->put('uploads/attachment.txt', 'instance data');
        $this->put('.git/HEAD', 'git');
        $this->put('src/helpers.php', '<?php helpers;');
        $this->put('migrations/20260101120000-add_logs.sql', 'CREATE TABLE logs(id INTEGER);');

        $tree = tb_pack_generator_tree($this->appDir);
        $this->assertArrayHasKey('index.php', $tree);
        $this->assertArrayHasKey('src/helpers.php', $tree);
        $this->assertArrayHasKey('migrations/20260101120000-add_logs.sql', $tree, 'the migrations travel with the pack');
        $this->assertArrayNotHasKey('config.php', $tree, 'credentials are never packed');
        $this->assertArrayNotHasKey('version.json', $tree, 'the instance identity is not packed');
        $this->assertArrayNotHasKey('pack.json', $tree, 'the pack format names are reserved');
        $this->assertArrayNotHasKey('board.sqlite', $tree, 'local databases are not packed');
        $this->assertArrayNotHasKey('backup.zip', $tree, 'local archives are not packed');
        $this->assertArrayNotHasKey('uploads/attachment.txt', $tree);
        $this->assertArrayNotHasKey('.git/HEAD', $tree);
    }

    public function test_base_tree_strips_the_github_wrapper(): void
    {
        $zipPath = $this->baseZip(['index.php' => 'base index', 'src/helpers.php' => 'base helpers']);
        $tree = tb_pack_generator_base_tree((string)file_get_contents($zipPath));
        unlink($zipPath);
        $this->assertSame(['index.php' => 'base index', 'src/helpers.php' => 'base helpers'], $tree);
    }

    public function test_generator_source_accepts_sha_and_tag(): void
    {
        $this->assertSame(str_repeat('a', 40), tb_pack_generator_source(str_repeat('a', 40))['sha'], 'a 40-hex ref pins a commit');
        $this->assertSame('v1.2.3', tb_pack_generator_source('v1.2.3')['sha'], 'anything else pins a release tag');
        $this->expectException(RuntimeException::class);
        tb_pack_generator_source('not a version ../..');
    }

    public function test_generator_refuses_a_tree_without_recorded_version(): void
    {
        $base = $this->baseZip(['index.php' => 'x']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no recorded version hash');
        tb_pack_generate('v1.0.0', $this->appDir, fn(string $url): string => (string)file_get_contents($base));
    }

    public function test_generator_refuses_an_unchanged_tree(): void
    {
        file_put_contents($this->appDir . '/version.json', '{"sha":"genhash0009"}');
        $this->put('index.php', 'same');
        $this->put('src/helpers.php', 'same');
        $base = $this->baseZip(['index.php' => 'same', 'src/helpers.php' => 'same']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing to pack');
        tb_pack_generate('genhash0009', $this->appDir, fn(string $url): string => (string)file_get_contents($base));
    }

    public function test_generated_pack_round_trips_through_the_69_engine(): void
    {
        // The generator instance: one changed file, one added file, one file
        // that is gone since the base, and one migration script (named per the
        // #68 convention, so the receiving engine scans and applies it).
        file_put_contents($this->appDir . '/version.json', '{"sha":"genhash0009"}');
        $this->put('index.php', 'generator index v2');
        $this->put('src/helpers.php', 'generator helpers');
        $this->put('src/brand_new.php', 'brand new module');
        $this->put('migrations/20260101120000-add_logs.sql', 'CREATE TABLE logs(id INTEGER);');
        $baseTree = ['index.php' => 'generator index v1', 'src/helpers.php' => 'generator helpers', 'obsolete.php' => 'removed upstream'];
        $base = $this->baseZip($baseTree);

        $pack = tb_pack_generate('basehash0001', $this->appDir, fn(string $url): string => (string)file_get_contents($base));
        unlink($base);
        $this->assertSame('basehash0001', $pack['previous_version']);
        $this->assertSame('genhash0009', $pack['new_version']);
        $this->assertSame(['migrations/20260101120000-add_logs.sql', 'src/brand_new.php'], $pack['added']);
        $this->assertSame(['index.php'], $pack['changed']);
        $this->assertSame(['obsolete.php'], $pack['deleted']);

        // The emitted ZIP must be a valid #69 pack: parse it with the engine.
        $parsed = tb_pack_read((string)file_get_contents($pack['path']));
        $this->assertSame('basehash0001', $parsed['previous_version']);
        $this->assertSame('genhash0009', $parsed['new_version']);
        $this->assertSame(['obsolete.php'], $parsed['deletions']);
        $this->assertSame('generator index v2', $parsed['files']['index.php']);
        $this->assertArrayNotHasKey('index.php', array_flip($parsed['deletions']));

        // A receiver at the base hash applies the pack and ends up with the
        // generator's tree (minus the instance-only files).
        $receiver = sys_get_temp_dir() . '/tb-packrecv-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($receiver . '/src', 0777, true);
        file_put_contents($receiver . '/version.json', '{"source":"branch","sha":"basehash0001"}');
        file_put_contents($receiver . '/index.php', 'generator index v1');
        file_put_contents($receiver . '/src/helpers.php', 'generator helpers');
        file_put_contents($receiver . '/obsolete.php', 'removed upstream');
        $receiverPdo = new PDO('sqlite:' . $receiver . '/board.sqlite');
        $receiverPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The #68 engine tracks applied scripts in the migrations table; the
        // app self-heals it on real requests, a bare test connection creates
        // it here with the schema.sql DDL.
        $receiverPdo->exec('CREATE TABLE migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version TEXT NOT NULL UNIQUE,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        try {
            $report = tb_pack_apply($receiverPdo, $parsed, $receiver);
            $this->assertSame('genhash0009', $report['new_version']);
            $this->assertSame('generator index v2', (string)file_get_contents($receiver . '/index.php'));
            $this->assertSame('brand new module', (string)file_get_contents($receiver . '/src/brand_new.php'));
            $this->assertSame('CREATE TABLE logs(id INTEGER);', (string)file_get_contents($receiver . '/migrations/20260101120000-add_logs.sql'));
            $this->assertFileDoesNotExist($receiver . '/obsolete.php');
            $tables = $receiverPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='logs'")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame(['logs'], $tables, 'the migrations the pack carries are applied on the receiver');
            $rows = $receiverPdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame(['20260101120000'], $rows, 'recorded under its own version stamp, not as a blob');
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($receiver, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) $file->isDir() ? @rmdir((string)$file) : @unlink((string)$file);
            @rmdir($receiver);
            @unlink($pack['path']);
        }
    }
}
