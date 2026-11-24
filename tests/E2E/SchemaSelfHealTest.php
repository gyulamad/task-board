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

    /** Rebuilds the statuses table without its color column to simulate a
     * pre-#42 database (task #42's status colours). */
    private function dropColorColumn(): void
    {
        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec('ALTER TABLE statuses DROP COLUMN color');
            return;
        }
        // SQLite: rebuild the table in its pre-#42 shape. Legacy rename
        // semantics keep the other tables' REFERENCES clauses pointing at
        // "statuses", exactly the dropProjectsSchema() trick above.
        $pdo->exec('PRAGMA legacy_alter_table=ON');
        $pdo->exec('ALTER TABLE statuses RENAME TO statuses_pre42');
        $pdo->exec('PRAGMA legacy_alter_table=OFF');
        $pdo->exec("CREATE TABLE statuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(40) NOT NULL UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_done INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec('INSERT INTO statuses(id,name,sort_order,is_done) SELECT id,name,sort_order,is_done FROM statuses_pre42');
        $pdo->exec('DROP TABLE statuses_pre42');
    }

    public function test_the_status_colour_column_is_self_healed(): void
    {
        $this->dropColorColumn();
        $admin = $this->adminSession();   // the first request runs the self-heal

        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $names = array_column($pdo->query('SHOW COLUMNS FROM statuses')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } else {
            $names = array_column($pdo->query('PRAGMA table_info(statuses)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        $this->assertContains('color', $names, 'The #42 colour column must be back after the self-heal.');
        // The heal keeps the seed rows, and the stored names/order are intact.
        $this->assertSame('3', (string)$pdo->query('SELECT COUNT(*) FROM statuses')->fetchColumn());

        // The healed column is immediately usable through the real form.
        $response = $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => 'POSTHEAL', 'color' => '#112233']);
        $this->assertSame(302, $response->status, 'Storing a colour must work right after the self-heal.');
        $row = TestServer::pdo()->query("SELECT color FROM statuses WHERE name='POSTHEAL'")->fetch();
        $this->assertSame('#112233', (string)$row['color']);
    }

    /** Removes the whole know-hows schema to simulate a pre-#53 database. */
    private function dropDocumentsSchema(): void
    {
        $pdo = TestServer::pdo();
        // Children first, parents last (MySQL refuses to drop a referenced table).
        $pdo->exec('DROP TABLE IF EXISTS document_attachments');
        $pdo->exec('DROP TABLE IF EXISTS document_comments');
        $pdo->exec('DROP TABLE IF EXISTS documents');
    }

    public function test_first_request_after_a_partial_deployment_recreates_the_know_hows_schema(): void
    {
        // #53 v2: the know-hows pages read the three knowledge-base tables
        // unconditionally and the v1 delivery shipped without the self-heal
        // section, so every board installed before #53 fataled with
        // "table documents doesn't exist" (42S02) on ?page=documents - the
        // exact PDOException admin reported from the deployed board.
        $this->dropDocumentsSchema();
        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->assertFalse((bool)$pdo->query("SHOW TABLES LIKE 'documents'")->fetchColumn());
        } else {
            $this->assertFalse((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='documents'")->fetchColumn());
        }

        // The very next request (the installer page here) must self-heal the
        // schema instead of fataling, and the knowledge base must work.
        $admin = $this->adminSession();
        $list = $admin->get('/?page=documents');
        $this->assertSame(200, $list->status);
        $this->assertTrue($list->contains('No know-hows yet. Write the first one.'));

        $response = $admin->post('/?page=document_new', [
            'action' => 'doc_save',
            'id' => '0',
            'title' => 'Self healed know-how',
            'content' => 'Written right after the schema self-heal.',
        ]);
        $this->assertSame(302, $response->status, 'Know-how creation must work on the recreated tables.');
        $this->assertTrue($admin->get('/?page=documents')->contains('Self healed know-how'));

        // The child tables are back too and their cascades are live: deleting
        // the document removes its rows (the task delete flow relies on this).
        $docId = (int)TestServer::pdo()->query("SELECT id FROM documents WHERE title='Self healed know-how'")->fetchColumn();
        $admin->post('/?page=document&id=' . $docId, ['action' => 'doc_delete', 'id' => (string)$docId]);
        $this->assertSame('0', (string)$pdo->query('SELECT COUNT(*) FROM document_comments')->fetchColumn());
    }

    public function test_first_request_after_a_partial_deployment_recreates_the_messages_schema(): void
    {
        // #56: the messages table follows the same self-heal discipline as
        // projects, saved_searches and documents - the navbar badge queries it
        // on every page, so a missing table would take the whole board down.
        $pdo = TestServer::pdo();
        $pdo->exec('DROP TABLE IF EXISTS messages');
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->assertFalse((bool)$pdo->query("SHOW TABLES LIKE 'messages'")->fetchColumn());
        } else {
            $this->assertFalse((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='messages'")->fetchColumn());
        }

        // The very next request (the installer page here) must self-heal the
        // schema instead of fataling, and the message board must work.
        $admin = $this->adminSession();
        $this->assertSame(200, $admin->get('/?page=messages')->status);

        $admin->post('/?page=users', ['action' => 'add_user', 'username' => 'healed', 'display_name' => 'Healed', 'password' => 'HealedPass12345', 'role' => 'user']);
        $recipientId = (int)$pdo->query("SELECT id FROM users WHERE username='healed'")->fetchColumn();
        // The composer way (#87): render the thread form first, then post with
        // its one-time send token.
        $composer = $admin->get('/?page=messages&u=' . $recipientId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders the one-time send token on the self-healed schema.');
        $this->assertSame(302, $admin->post('/?page=messages&u=' . $recipientId, ['action' => 'send_message', 'recipient_id' => (string)$recipientId, 'body' => 'Sent right after the schema self-heal.', 'send_token' => $m[1]])->status, 'Message sending must work on the recreated table.');
        $this->assertSame('1', (string)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn());
    }
}
