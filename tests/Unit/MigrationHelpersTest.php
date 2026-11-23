<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Unit tests for the migration scan/split helpers (#59/#68): which files
 * count as migrations, how they sort, and how a script is cut into
 * statements. These run without any server or database.
 */
final class MigrationHelpersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tb-migration-scan-' . getmypid() . '-' . mt_rand();
        if (is_dir($this->dir)) {
            $this->rrmdir($this->dir);
        }
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->rrmdir($this->dir);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->rrmdir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    public function test_a_missing_or_empty_directory_scans_to_nothing(): void
    {
        $this->assertSame([], tb_migration_scan($this->dir . '/does-not-exist'));
        $this->assertSame([], tb_migration_scan($this->dir));
    }

    public function test_only_version_marked_sql_files_are_migrations(): void
    {
        file_put_contents($this->dir . '/20260101120000-create-something.sql', 'SELECT 1;');
        file_put_contents($this->dir . '/20260101120100-bare-version.sql', 'SELECT 1;');
        file_put_contents($this->dir . '/notes.txt', 'not sql');
        file_put_contents($this->dir . '/README.md', 'docs');
        file_put_contents($this->dir . '/not-a-stamp.sql', 'SELECT 1;');
        file_put_contents($this->dir . '/20260101-short.sql', 'SELECT 1;');
        file_put_contents($this->dir . '/2026-01-01-dashes.sql', 'SELECT 1;');
        $versions = array_map(fn(array $entry): string => $entry['version'], tb_migration_scan($this->dir));
        $this->assertSame(['20260101120000', '20260101120100'], $versions);
    }

    public function test_files_sort_by_version_then_filename(): void
    {
        file_put_contents($this->dir . '/20260101120000-b.sql', 'SELECT 1;');
        file_put_contents($this->dir . '/20260101120000-a.sql', 'SELECT 1;');
        file_put_contents($this->dir . '/20260101110000-earlier.sql', 'SELECT 1;');
        $files = array_map(fn(array $entry): string => $entry['file'], tb_migration_scan($this->dir));
        $this->assertSame(['20260101110000-earlier.sql', '20260101120000-a.sql', '20260101120000-b.sql'], $files);
    }

    public function test_a_script_splits_on_line_ending_semicolons(): void
    {
        $sql = "CREATE TABLE a (id INTEGER);\nINSERT INTO a VALUES (1);\nUPDATE a SET id = 2 WHERE id = 1;";
        $this->assertSame(
            ['CREATE TABLE a (id INTEGER)', 'INSERT INTO a VALUES (1)', 'UPDATE a SET id = 2 WHERE id = 1'],
            tb_migration_split_statements($sql)
        );
    }

    public function test_comment_only_chunks_are_dropped_and_comments_inside_statements_survive(): void
    {
        $sql = "-- leading note\nCREATE TABLE a (id INTEGER); -- trailing note\n-- only a comment\n";
        $this->assertSame(["-- leading note\nCREATE TABLE a (id INTEGER)"], tb_migration_split_statements($sql));
    }

    public function test_a_semicolon_inside_a_single_line_value_does_not_split(): void
    {
        $sql = "INSERT INTO a (note) VALUES ('a;b');";
        $this->assertSame(["INSERT INTO a (note) VALUES ('a;b')"], tb_migration_split_statements($sql));
    }

    public function test_the_split_rule_is_mechanical_and_does_not_parse_strings(): void
    {
        // Pinned deliberately: like the schema importer, the splitter only
        // looks for line-ending semicolons and never parses string literals,
        // so a value containing ";\n" yields three chunks. Migration scripts
        // therefore must not carry multi-line string values (documented in
        // migrations/README.md).
        $sql = "INSERT INTO a (note) VALUES ('x');\nINSERT INTO a (note) VALUES ('end of line;\nnot a split');";
        $this->assertCount(3, tb_migration_split_statements($sql));
    }
}
