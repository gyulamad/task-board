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
        $pdo->exec('DROP TABLE IF EXISTS project_assignees');
        $pdo->exec('DROP TABLE IF EXISTS projects');
        $pdo->exec('ALTER TABLE tasks RENAME TO tasks_pre9');
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
        $this->assertTrue($page->contains('All projects'), 'The project filter must be back after the self-heal.');

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
        $this->assertTrue($page->contains('All projects'));

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
        // The seed data must not be disturbed.
        $this->assertSame('3', (string)$pdo->query('SELECT COUNT(*) FROM statuses')->fetchColumn());
    }
}
