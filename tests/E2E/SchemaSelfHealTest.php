<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use PDO;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #24: a deployment that lands without the documented upgrade SQL used
 * to fatal on every list page with "table projects doesn't exist" (42S02),
 * because the task pages query the projects schema unconditionally. The
 * idempotent self-heal in tb_migrate_schema() must recreate the missing
 * schema parts on the first request, exactly like the scheduler (#18) and
 * archive (#12) column upgrades already do.
 */
final class SchemaSelfHealTest extends E2eTestCase
{
    /** Removes the whole projects schema to simulate a pre-#9 database. */
    private function dropProjectsSchema(): void
    {
        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // MySQL refuses to drop a table that another table's FK still
            // references, so the tasks-side FK/column must go first.
            $pdo->exec('ALTER TABLE tasks DROP FOREIGN KEY fk_task_project');
            $pdo->exec('ALTER TABLE tasks DROP COLUMN project_id');
            $pdo->exec('DROP TABLE IF EXISTS project_assignees');
            $pdo->exec('DROP TABLE IF EXISTS projects');
            return;
        }
        // SQLite cannot drop a column that its own table's FK definition
        // references, so rebuild the tasks table exactly like the pre-#9
        // schema.sql had it (same columns minus project_id / fk_task_project).
        $pdo->exec('PRAGMA foreign_keys=OFF');
        // A modern-SQLite RENAME rewrites REFERENCES clauses in the OTHER
        // tables (comments, task_history, ...) to point at tasks_pre9, which
        // this fixture drops at the end - later INSERTs would then die with
        // "no such table: main.tasks_pre9". Legacy rename semantics keep the
        // references pointing at "tasks", the table recreated right below.
        $pdo->exec('PRAGMA legacy_alter_table=ON');
        $pdo->exec('DROP TABLE IF EXISTS project_assignees');
        $pdo->exec('DROP TABLE IF EXISTS projects');
        $pdo->exec('ALTER TABLE tasks RENAME TO tasks_pre9');
        $pdo->exec('PRAGMA legacy_alter_table=OFF');
        $pdo->exec("CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            status_id INTEGER NOT NULL,
            parent_id INTEGER NULL,
            schedule_period VARCHAR(20) NULL DEFAULT NULL,
            created_by INTEGER NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at TIMESTAMP NULL DEFAULT NULL,
            CONSTRAINT fk_task_status FOREIGN KEY (status_id) REFERENCES statuses(id),
            CONSTRAINT fk_task_parent FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE SET NULL,
            CONSTRAINT fk_task_creator FOREIGN KEY (created_by) REFERENCES users(id)
        )");
        $pdo->exec('INSERT INTO tasks(id,title,description,status_id,parent_id,schedule_period,created_by,created_at,updated_at,archived_at)
            SELECT id,title,description,status_id,parent_id,schedule_period,created_by,created_at,updated_at,archived_at FROM tasks_pre9');
        $pdo->exec('DROP TABLE tasks_pre9');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    public function test_first_request_after_a_partial_deployment_recreates_the_projects_schema(): void
    {
        $this->dropProjectsSchema();
        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->assertFalse((bool)$pdo->query("SHOW TABLES LIKE 'projects'")->fetchColumn());
        } else {
            $this->assertFalse((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='projects'")->fetchColumn());
        }

        // The very next request (the installer page here) must self-heal the
        // schema instead of fataling, and the board must work normally after it.
        $admin = $this->adminSession();

        $page = $admin->get('/?page=tasks');
        $this->assertSame(200, $page->status);
        // #25 replaced the placeholder option with a labeled multi-select, so
        // the project filter is now rendered as id="filter-project".
        $this->assertTrue($page->contains('id="filter-project"'), 'The project filter must be back after the self-heal.');

        $response = $admin->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => 'Self healed project',
            'description' => 'Created right after the schema self-heal',
            'status_id' => '1',
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status, 'Project creation must work on the recreated tables.');
        $this->assertTrue($admin->get('/?page=projects')->contains('Self healed project'));

        $row = TestServer::pdo()->query('SELECT COUNT(*) FROM project_assignees')->fetchColumn();
        $this->assertSame('0', (string)$row);
    }

    public function test_healthy_database_is_left_untouched_by_the_self_heal(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=tasks');
        $this->assertSame(200, $page->status);
        // #25 replaced the placeholder option with a labeled multi-select, so
        // the project filter is now rendered as id="filter-project".
        $this->assertTrue($page->contains('id="filter-project"'));

        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $columns = $pdo->query('SHOW COLUMNS FROM tasks')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'Field');
        } else {
            $columns = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($columns, 'name');
        }
        $this->assertContains('schedule_period', $names);
        $this->assertContains('archived_at', $names);
        $this->assertContains('project_id', $names);
        $this->assertContains('schedule_status_id', $names, 'The #31 scheduler target columns must exist on a healthy database.');
        $this->assertContains('schedule_assignees', $names, 'The #31 scheduler target columns must exist on a healthy database.');
        // The seed data must not be disturbed.
        $this->assertSame('3', (string)$pdo->query('SELECT COUNT(*) FROM statuses')->fetchColumn());
    }

    public function test_the_scheduler_target_columns_are_self_healed(): void
    {
        // The pre-#9 tasks table this fixture rebuilds carries neither the
        // #31 target columns (SQLite mode strips the whole tasks table back to
        // its 2024 shape) nor the projects schema, so one heal must restore
        // everything and the scheduler targets must be usable immediately.
        $this->dropProjectsSchema();
        $admin = $this->adminSession();   // the first request runs the self-heal

        $id = $admin->createTask('Post heal schedule');
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Post heal schedule',
            'description' => '',
            'status_id' => '1',
            'parent_id' => '',
            'schedule_period' => 'hourly',
            'schedule_status_id' => '2',
            'schedule_assignees' => ['1'],
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status, 'Storing scheduler targets must work right after the self-heal.');

        $row = TestServer::pdo()->query('SELECT schedule_status_id, schedule_assignees FROM tasks WHERE id=' . $id)->fetch();
        $this->assertSame(2, (int)$row['schedule_status_id']);
        $this->assertSame('1', (string)$row['schedule_assignees']);
    }
}
