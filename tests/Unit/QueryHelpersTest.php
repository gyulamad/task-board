<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use TaskBoard\Tests\Support\Schema;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Unit tests for the shared data-access helpers in src/helpers.php that need
 * a database. They run against a throwaway SQLite database built from
 * schema.sql - no web server is booted.
 */
final class QueryHelpersTest extends TestCase
{
    private PDO $pdo;
    private string $dbFile;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->dbFile = sys_get_temp_dir() . '/task-board-query-helpers-' . getmypid() . '-' . uniqid() . '.sqlite';
        Schema::createSqlite($this->dbFile);
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->dbFile)) {
            @unlink($this->dbFile);
        }
        parent::tearDown();
    }

    /** Inserts two users, one task and one project with fixed, known ids. */
    private function seed(): void
    {
        $this->pdo->exec('INSERT INTO users(id,username,password_hash,display_name,role) VALUES
            (1,\'admin\',\'$2y$10$dummyhashaaaaaaaaaaaaaaaaaaaaaaaaaaa\',\'Administrator\',\'admin\'),
            (2,\'zita\',\'$2y$10$dummyhashbbbbbbbbbbbbbbbbbbbbbbbbbbb\',\'Zita User\',\'user\'),
            (3,\'bob\',\'$2y$10$dummyhashccccccccccccccccccccccccccc\',\'Bob Helper\',\'user\')');
        $this->pdo->exec("INSERT INTO tasks(id,title,description,status_id,created_by,archived_at) VALUES
            (10,'Assigned task','x',1,1,NULL),
            (11,'Other task','x',2,1,'2026-01-02 03:04:05'),
            (12,'Lonely task','x',3,1,NULL)");
        $this->pdo->exec("INSERT INTO task_assignees(task_id,user_id) VALUES (10,1),(10,3),(11,2)");
        $this->pdo->exec("INSERT INTO projects(id,name,description,status_id,created_by) VALUES
            (20,'Apollo','x',1,1),
            (21,'Borealis','x',2,1)");
        $this->pdo->exec("INSERT INTO project_assignees(project_id,user_id) VALUES (20,1),(20,2)");
    }

    public function test_link_type_catalog_matches_the_schema_enum(): void
    {
        $this->assertSame(
            ['blocks' => 'Blocks', 'depends_on' => 'Depends on', 'relates_to' => 'Relates to'],
            TASK_BOARD_LINK_TYPES
        );
    }

    public function test_user_count_counts_registered_accounts(): void
    {
        $this->seed();
        $this->assertSame(3, user_count($this->pdo));
        $this->pdo->exec('DELETE FROM users WHERE id=3');
        $this->assertSame(2, user_count($this->pdo));
    }

    public function test_find_task_returns_the_row_or_null(): void
    {
        $this->seed();
        $task = find_task($this->pdo, 11);
        $this->assertNotNull($task);
        $this->assertSame('Other task', $task['title']);
        $this->assertSame('2026-01-02 03:04:05', $task['archived_at']);
        $this->assertNull(find_task($this->pdo, 999));
    }

    public function test_find_project_returns_the_row_or_null(): void
    {
        $this->seed();
        $project = find_project($this->pdo, 21);
        $this->assertNotNull($project);
        $this->assertSame('Borealis', $project['name']);
        $this->assertNull(find_project($this->pdo, 999));
    }

    public function test_status_and_project_existence_checks(): void
    {
        $this->seed();
        $this->assertTrue(status_exists($this->pdo, 1));
        $this->assertFalse(status_exists($this->pdo, 999));
        $this->assertTrue(project_exists($this->pdo, 20));
        $this->assertFalse(project_exists($this->pdo, 999));
    }

    public function test_all_statuses_are_ordered_by_sort_order(): void
    {
        $this->seed();
        $this->assertSame(
            ['TODO', 'IN PROGRESS', 'DONE'],
            array_column(all_statuses($this->pdo), 'name')
        );
    }

    public function test_user_options_are_ordered_by_display_name(): void
    {
        $this->seed();
        $this->assertSame(
            ['Administrator', 'Bob Helper', 'Zita User'],
            array_column(user_options($this->pdo), 'display_name')
        );
    }

    public function test_task_assignee_names_are_grouped_by_task(): void
    {
        $this->seed();
        $names = task_assignee_names_by_task($this->pdo, [10, 11, 12]);
        $this->assertSame(['Administrator', 'Bob Helper'], $names[10]);
        $this->assertSame(['Zita User'], $names[11]);
        // Task 12 has no assignees, so it is absent from the result.
        $this->assertArrayNotHasKey(12, $names);
    }

    public function test_task_assignee_names_ignore_empty_or_invalid_ids(): void
    {
        $this->seed();
        $this->assertSame([], task_assignee_names_by_task($this->pdo, []));
        // Zero, negative and duplicated ids are collapsed away safely.
        $names = task_assignee_names_by_task($this->pdo, [0, -3, 10, 10, '11']);
        $this->assertSame(['Administrator', 'Bob Helper'], $names[10]);
        $this->assertSame(['Zita User'], $names[11]);
    }

    public function test_project_assignee_names_are_grouped_by_project(): void
    {
        $this->seed();
        $names = project_assignee_names_by_project($this->pdo, [20, 21]);
        $this->assertSame(['Administrator', 'Zita User'], $names[20]);
        $this->assertArrayNotHasKey(21, $names);
        $this->assertSame([], project_assignee_names_by_project($this->pdo, [0]));
    }

    public function test_task_is_archived_depends_on_a_non_empty_timestamp(): void
    {
        $this->assertFalse(task_is_archived(['archived_at' => null]));
        $this->assertFalse(task_is_archived(['archived_at' => '']));
        $this->assertTrue(task_is_archived(['archived_at' => '2026-01-02 03:04:05']));
        $this->assertFalse(task_is_archived([]));
    }

    public function test_bulk_apply_grants_administrators_every_id(): void
    {
        $this->seed();
        $_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'display_name' => 'Administrator', 'role' => 'admin'];
        $result = tb_bulk_apply(
            $this->pdo,
            [10, 11, 12],
            'SELECT created_by FROM tasks WHERE id=?',
            'UPDATE tasks SET archived_at=CURRENT_TIMESTAMP WHERE id=? AND archived_at IS NULL'
        );
        $this->assertSame(['done' => 3, 'skipped' => 0], $result);
        $left = (int)$this->pdo->query('SELECT COUNT(*) FROM tasks WHERE archived_at IS NULL')->fetchColumn();
        $this->assertSame(0, $left);
    }

    public function test_bulk_apply_skips_tasks_the_user_does_not_own(): void
    {
        $this->seed();
        $this->pdo->exec('UPDATE tasks SET created_by=2 WHERE id=10');
        $this->pdo->exec('UPDATE tasks SET created_by=3 WHERE id=11');
        $_SESSION['user'] = ['id' => 2, 'username' => 'zita', 'display_name' => 'Zita User', 'role' => 'user'];
        $result = tb_bulk_apply(
            $this->pdo,
            [10, 11, 12],
            'SELECT created_by FROM tasks WHERE id=?',
            'UPDATE tasks SET archived_at=CURRENT_TIMESTAMP WHERE id=? AND archived_at IS NULL'
        );
        // Task 10 is Zita's and got archived; the tasks of the others stay untouched.
        $this->assertSame(['done' => 1, 'skipped' => 2], $result);
        $this->assertNotFalse($this->pdo->query('SELECT archived_at FROM tasks WHERE id=10 AND archived_at IS NOT NULL')->fetchColumn());
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM tasks WHERE id=12 AND archived_at IS NOT NULL')->fetchColumn());
        $this->assertSame('2026-01-02 03:04:05', (string)$this->pdo->query('SELECT archived_at FROM tasks WHERE id=11')->fetchColumn());
    }

    public function test_bulk_flash_renders_the_singular_plural_and_skip_forms(): void
    {
        $this->assertSame('2 tasks archived.', tb_bulk_flash(2, 0, 'task', 'archived', 'only administrators or the task creator can archive a task.'));
        $this->assertSame('1 task archived.', tb_bulk_flash(1, 0, 'task', 'archived', 'only administrators or the task creator can archive a task.'));
        $this->assertSame(
            '1 project deleted. 2 skipped: only administrators or the project creator can delete a project.',
            tb_bulk_flash(1, 2, 'project', 'deleted', 'only administrators or the project creator can delete a project.')
        );
        $this->assertSame('3 projects deleted.', tb_bulk_flash(3, 0, 'project', 'deleted', 'only administrators or the project creator can delete a project.'));
    }
}
