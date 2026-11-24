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
 * Task #103 - the two-phase update install: the allowlist that decides what
 * an update writes must come from the archive's own updater.php (phase 2)
 * instead of from the updater that is already running on the instance, which
 * is stale by definition. Its staleness caused the 2026-11-24 outage: the
 * running pre-#57-v2 list no longer knew src/crud.php, so installing develop
 * silently skipped the file and the board came up with a broken src/
 * directory.
 *
 * The tests exercise the real engine against a temporary application
 * directory and in-memory branch archives (codeload layout: one root
 * folder), reproducing the incident and pinning the fail-fast and rollback
 * contracts. No server, no GitHub.
 */
final class UpdateInstallTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        $this->appDir = sys_get_temp_dir() . '/tb-update-app-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!is_dir($this->appDir)) mkdir($this->appDir, 0777, true);
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

    /** A minimal updater.php source declaring the given allowlist. */
    private function updaterSource(array $allowlist): string
    {
        $entries = implode(",\n    ", array_map(fn(string $p): string => var_export($p, true), $allowlist));
        // Comments inside the literal mirror the shipped updater.php layout.
        return "<?php\nconst TASK_BOARD_UPDATE_FILES = [\n    // keep in sync with the repository layout\n    {$entries},\n];\n\nfunction tb_no_side_effect(): void {}\n";
    }

    /** Builds a branch archive ZIP in the codeload layout: root folder + files. */
    private function buildArchive(array $entries, string $root = 'task-board-abc123', bool $withRoot = true): string
    {
        $zip = new ZipArchive();
        $path = tempnam(sys_get_temp_dir(), 'tb-update-test-') . '.zip';
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $this->assertTrue($zip->addFromString(($withRoot ? $root . '/' : '') . $name, $content) === true);
        }
        $zip->close();
        return (string)file_get_contents($path);
    }

    private function put(string $relative, string $content): void
    {
        $target = $this->appDir . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
        file_put_contents($target, $content);
    }

    private function read(string $relative): ?string
    {
        return is_file($this->appDir . '/' . $relative) ? (string)file_get_contents($this->appDir . '/' . $relative) : null;
    }

    public function test_allowlist_is_read_from_the_real_shipped_updater(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/updater.php');
        $this->assertSame(TASK_BOARD_UPDATE_FILES, tb_update_allowlist_from_source($source));
    }

    public function test_allowlist_reader_refuses_missing_or_malformed_definitions(): void
    {
        $cases = [
            'no const at all' => "<?php\nfunction tb_x(): void {}\n",
            'const with no array' => "<?php\nconst TASK_BOARD_UPDATE_FILES = 'nope';\n",
            'expression entries' => "<?php\nconst TASK_BOARD_UPDATE_FILES = ['index.php', PHP_VERSION];\n",
            'double quoted entries' => "<?php\nconst TASK_BOARD_UPDATE_FILES = [\"index.php\"];\n",
            'unsafe traversal path' => "<?php\nconst TASK_BOARD_UPDATE_FILES = ['index.php', '../evil.php'];\n",
            'absolute path' => "<?php\nconst TASK_BOARD_UPDATE_FILES = ['index.php', '/etc/passwd'];\n",
            'protected config.php' => "<?php\nconst TASK_BOARD_UPDATE_FILES = ['index.php', 'updater.php', 'config.php'];\n",
            'missing updater.php entry' => "<?php\nconst TASK_BOARD_UPDATE_FILES = ['index.php'];\n",
            'duplicate entries' => "<?php\nconst TASK_BOARD_UPDATE_FILES = ['index.php', 'updater.php', 'index.php'];\n",
            'unterminated list' => "<?php\nconst TASK_BOARD_UPDATE_FILES = ['index.php', 'updater.php';\n",
        ];
        foreach ($cases as $why => $source) {
            try {
                tb_update_allowlist_from_source($source);
                $this->fail("The reader must refuse: $why");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('TASK_BOARD_UPDATE_FILES', $e->getMessage(), "Refusal must name the list: $why");
                $this->assertStringContainsString('Nothing was written', $e->getMessage(), "Refusal must promise no writes: $why");
            }
        }
    }

    public function test_allowlist_reader_accepts_the_declared_list(): void
    {
        $list = ['index.php', 'updater.php', 'src/helpers.php', 'src/crud.php'];
        $this->assertSame($list, tb_update_allowlist_from_source($this->updaterSource($list)));
    }

    public function test_extraction_returns_every_regular_archive_file(): void
    {
        // The pre-#103 extraction dropped anything the running allowlist did
        // not know - exactly how src/crud.php was lost in the outage. It must
        // now hand every regular file to the install decision.
        $archive = $this->buildArchive([
            'index.php' => '<?php echo "new";',
            'updater.php' => $this->updaterSource(['index.php', 'updater.php']),
            'src/helpers.php' => '<?php // helpers',
            'src/brand-new-file.php' => '<?php // arrived after the running updater shipped',
            'tests/NotDeployedTest.php' => '<?php // never installed',
        ]);
        $files = tb_update_archive_files($archive);
        $this->assertSame(
            ['index.php', 'updater.php', 'src/helpers.php', 'src/brand-new-file.php', 'tests/NotDeployedTest.php'],
            array_keys($files)
        );
    }

    public function test_extraction_still_refuses_broken_archives(): void
    {
        $updater = $this->updaterSource(['index.php', 'updater.php']);
        // Entries sitting directly at the zip root (no codeload root folder)
        // are skipped by the layout rule, so the required-files check fires.
        try {
            tb_update_archive_files($this->buildArchive(
                ['index.php' => '<?php echo "new";', 'updater.php' => $updater],
                withRoot: false
            ));
            $this->fail('An archive without the root folder layout must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('missing required application files', $e->getMessage());
        }
    }

    /** Builds a codeload-layout archive whose `$linkName` entry is a symlink. */
    private function buildArchiveWithSymlinkEntry(string $linkName): string
    {
        $zip = new ZipArchive();
        $path = tempnam(sys_get_temp_dir(), 'tb-update-test-') . '.zip';
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('task-board-abc123/index.php', '<?php echo "new";');
        $zip->addFromString('task-board-abc123/updater.php', $this->updaterSource(['index.php', 'updater.php']));
        $zip->addFromString('task-board-abc123/' . $linkName, 'somewhere/else');
        $zip->setExternalAttributesName('task-board-abc123/' . $linkName, ZipArchive::OPSYS_UNIX, 0120000 << 16);
        $zip->close();
        return (string)file_get_contents($path);
    }

    public function test_extraction_refuses_a_symbolic_link_entry_and_names_it(): void
    {
        // Task #116: develop shipped with an accidental tracked `vendor`
        // symlink (added by the round-11 rebase, slipped past the `vendor/`
        // ignore rule because that only matches directories). The install
        // refused the whole archive with a message that did not name the
        // entry, so the failure needed a board ticket to debug. The refusal
        // stays fail-fast (the #103 discipline) but now names the path.
        try {
            tb_update_archive_files($this->buildArchiveWithSymlinkEntry('vendor'));
            $this->fail('An archive carrying a symbolic link entry must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('The archive contains a symbolic link', $e->getMessage());
            $this->assertStringContainsString('vendor', $e->getMessage(), 'the refusal must name the offending entry');
        }
        // The same guard covers a link nested deeper in the tree.
        try {
            tb_update_archive_files($this->buildArchiveWithSymlinkEntry('src/helpers.php'));
            $this->fail('A nested symbolic link entry must be refused too.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
            $this->assertStringContainsString('src/helpers.php', $e->getMessage());
        }
    }

    public function test_the_incident_install_ends_with_the_new_boot_required_file_present(): void
    {
        // The exact 2026-11-24 shape: a running updater whose allowlist is
        // stale (knows src/helpers.php, not the new boot-required file) and
        // an archive (develop) that adds that file and ships a complete
        // allowlist. index.php in the archive requires the new file to boot.
        $stale = $this->updaterSource(['index.php', 'updater.php', 'src/helpers.php']);
        $complete = $this->updaterSource(['index.php', 'updater.php', 'src/helpers.php', 'src/crud.php']);
        $this->put('index.php', "<?php\nrequire __DIR__ . '/src/helpers.php';\n");
        $this->put('updater.php', $stale);
        $this->put('src/helpers.php', '<?php // old helpers');

        $files = tb_update_archive_files($this->buildArchive([
            'index.php' => "<?php\nrequire __DIR__ . '/src/helpers.php';\nrequire __DIR__ . '/src/crud.php';\n",
            'updater.php' => $complete,
            'src/helpers.php' => '<?php // new helpers',
            'src/crud.php' => '<?php // the CRUD engine the stale list did not know',
        ]));

        $backup = tb_update_install($files, $this->appDir);
        $this->assertStringContainsString('task-board-backup-', $backup);
        $this->assertFileExists($this->appDir . '/src/crud.php', 'The boot-required file must be installed, not silently skipped.');
        $this->assertSame('<?php // the CRUD engine the stale list did not know', $this->read('src/crud.php'));
        $this->assertSame('<?php // new helpers', $this->read('src/helpers.php'));
        $this->assertSame($complete, $this->read('updater.php'));
        $this->assertStringContainsString('require __DIR__ . \'/src/crud.php\';', (string)$this->read('index.php'));
    }

    public function test_install_refuses_when_a_new_allowlist_file_is_missing_everywhere(): void
    {
        // Fail-fast: the archive's own allowlist names src/gone.php, but the
        // archive does not carry it and the instance does not have it. The
        // whole install must be refused before any write happens.
        $this->put('index.php', '<?php // old index');
        $this->put('updater.php', $this->updaterSource(['index.php', 'updater.php']));
        $files = [
            'index.php' => '<?php // new index',
            'updater.php' => $this->updaterSource(['index.php', 'updater.php', 'src/gone.php']),
        ];

        try {
            tb_update_install($files, $this->appDir);
            $this->fail('The install must refuse a source that cannot boot.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('src/gone.php', $e->getMessage());
            $this->assertStringContainsString('Refusing to install', $e->getMessage());
        }
        $this->assertSame('<?php // old index', $this->read('index.php'), 'Nothing may be written on a failed fail-fast.');
        $this->assertSame($this->updaterSource(['index.php', 'updater.php']), $this->read('updater.php'), 'The running updater.php must survive a refused install.');
        $this->assertFileDoesNotExist($this->appDir . '/src/gone.php');
    }

    public function test_install_ignores_files_the_new_allowlist_does_not_cover(): void
    {
        $this->put('updater.php', $this->updaterSource(['index.php', 'updater.php']));
        $files = tb_update_archive_files($this->buildArchive([
            'index.php' => '<?php // new index',
            'updater.php' => $this->updaterSource(['index.php', 'updater.php']),
            'tests/LaterTest.php' => '<?php // in the repo, never deployed by an update',
        ]));
        tb_update_install($files, $this->appDir);
        $this->assertSame('<?php // new index', $this->read('index.php'));
        $this->assertFileDoesNotExist($this->appDir . '/tests/LaterTest.php', 'Files outside the new allowlist are not deployed.');
    }

    public function test_rollback_restores_everything_including_the_phase_one_updater(): void
    {
        $oldUpdater = $this->updaterSource(['index.php', 'updater.php']);
        $newUpdater = $this->updaterSource(['index.php', 'updater.php', 'src/helpers.php', 'src/locked.php']);
        $this->put('index.php', '<?php // old index');
        $this->put('updater.php', $oldUpdater);
        $this->put('src/helpers.php', '<?php // old helpers');
        // A directory sitting where the archive wants to write a file: the
        // rename fails mid-install, after phase 1 already replaced updater.php.
        mkdir($this->appDir . '/src/locked.php', 0777, true);

        $files = [
            'index.php' => '<?php // new index',
            'updater.php' => $newUpdater,
            'src/helpers.php' => '<?php // new helpers',
            'src/locked.php' => '<?php // this write fails',
        ];

        try {
            tb_update_install($files, $this->appDir);
            $this->fail('The install must fail on the unwritable target.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('src/locked.php', $e->getMessage());
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }
        $this->assertSame($oldUpdater, $this->read('updater.php'), 'The phase-1 updater.php replacement must be rolled back too.');
        $this->assertSame('<?php // old index', $this->read('index.php'));
        $this->assertSame('<?php // old helpers', $this->read('src/helpers.php'));
    }

    public function test_install_bootstraps_a_missing_sub_directory(): void
    {
        $this->put('updater.php', $this->updaterSource(['index.php', 'updater.php', 'src/helpers.php']));
        $files = tb_update_archive_files($this->buildArchive([
            'index.php' => '<?php // new index',
            'updater.php' => $this->updaterSource(['index.php', 'updater.php', 'src/helpers.php']),
            'src/helpers.php' => '<?php // helpers of the split layout',
        ]));
        $this->assertFileDoesNotExist($this->appDir . '/src');
        tb_update_install($files, $this->appDir);
        $this->assertSame('<?php // helpers of the split layout', $this->read('src/helpers.php'));
    }

    public function test_a_phase_one_only_failure_writes_nothing_else(): void
    {
        // updater.php itself cannot be replaced (a directory occupies the
        // target): the failure happens before any other write.
        $oldUpdater = $this->updaterSource(['index.php', 'updater.php']);
        $this->put('index.php', '<?php // old index');
        $this->put('updater.php', $oldUpdater);
        $this->assertStringContainsString('TASK_BOARD_UPDATE_FILES', $oldUpdater);
        @unlink($this->appDir . '/updater.php');
        mkdir($this->appDir . '/updater.php', 0777, true);

        $files = [
            'index.php' => '<?php // new index',
            'updater.php' => $this->updaterSource(['index.php', 'updater.php']),
        ];
        try {
            tb_update_install($files, $this->appDir);
            $this->fail('Replacing updater.php with a directory in the way must fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('updater.php', $e->getMessage());
        }
        $this->assertSame('<?php // old index', $this->read('index.php'));
        $this->assertDirectoryExists($this->appDir . '/updater.php');
    }
}
