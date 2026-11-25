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

    public function test_project_autofill_capture_tracks_a_single_picked_project(): void
    {
        // #130: wherever the board can pick exactly one project - the three
        // filter-card views or the project page - the id rides in the session
        // for the create form; anything else that can be judged clears it,
        // and the pages without a project selection never judge it.
        $this->seed();
        $_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'display_name' => 'Administrator', 'role' => 'admin'];

        // The three filter-card views store exactly one real project...
        foreach (['tasks', 'kanban', 'waterflow'] as $page) {
            $_GET = ['page' => $page, 'project' => [(string)21]];
            tb_project_autofill_capture($page, $this->pdo);
            $this->assertSame(21, $_SESSION['tb_project_autofill'], 'The ' . $page . ' filter stores the single picked project.');
        }
        // ...the legacy scalar shape is the same single pick...
        $_GET = ['page' => 'tasks', 'project' => '21'];
        tb_project_autofill_capture('tasks', $this->pdo);
        $this->assertSame(21, $_SESSION['tb_project_autofill']);
        // ...and the project page stores the project it opens.
        $_GET = ['page' => 'project', 'id' => '20'];
        tb_project_autofill_capture('project', $this->pdo);
        $this->assertSame(20, $_SESSION['tb_project_autofill']);

        // Everything else clears: several values, the "No project"
        // pseudo-value, an unknown id, no filter at all, a broken project id.
        foreach ([['project' => ['20', '21']], ['project' => ['none']], ['project' => ['999']], ['project' => ['x']], []] as $view) {
            $_GET = ['page' => 'tasks'] + $view;
            tb_project_autofill_capture('tasks', $this->pdo);
            $this->assertArrayNotHasKey('tb_project_autofill', $_SESSION, 'The view ' . json_encode($view) . ' must clear the stored pick.');
        }

        // A stored pick survives the pages that cannot judge a selection,
        // and the read re-checks the project still exists.
        $_GET = ['page' => 'project', 'id' => '21'];
        tb_project_autofill_capture('project', $this->pdo);
        $_GET = [];
        tb_project_autofill_capture('archive', $this->pdo);
        tb_project_autofill_capture('users', $this->pdo);
        $this->assertSame(21, $_SESSION['tb_project_autofill'], 'The archive and the user list never judge the pick.');
        $this->assertSame(21, tb_project_autofill_current($this->pdo));
        $this->pdo->exec('DELETE FROM projects WHERE id=21');
        $this->assertNull(tb_project_autofill_current($this->pdo), 'A since-deleted project autofills nothing.');

        // A signed-out visitor captures nothing, and a POST never judges.
        unset($_SESSION['user'], $_SESSION['tb_project_autofill']);
        $_GET = ['page' => 'tasks', 'project' => ['20']];
        tb_project_autofill_capture('tasks', $this->pdo);
        $this->assertArrayNotHasKey('tb_project_autofill', $_SESSION);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'display_name' => 'Administrator', 'role' => 'admin'];
        tb_project_autofill_capture('tasks', $this->pdo);
        $this->assertArrayNotHasKey('tb_project_autofill', $_SESSION, 'A form POST to a list page does not judge the selection.');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
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

    public function test_task_assignees_are_grouped_by_task(): void
    {
        $this->seed();
        $assignees = task_assignees_by_task($this->pdo, [10, 11, 12]);
        $this->assertSame(['Administrator', 'Bob Helper'], array_column($assignees[10], 'display_name'));
        $this->assertSame(['Zita User'], array_column($assignees[11], 'display_name'));
        // Every row carries the activity stamp (#30); the seeded users have none.
        $this->assertSame([null, null], array_column($assignees[10], 'last_seen_at'));
        // Task 12 has no assignees, so it is absent from the result.
        $this->assertArrayNotHasKey(12, $assignees);
    }

    public function test_task_assignees_ignore_empty_or_invalid_ids(): void
    {
        $this->seed();
        $this->assertSame([], task_assignees_by_task($this->pdo, []));
        // Zero, negative and duplicated ids are collapsed away safely.
        $assignees = task_assignees_by_task($this->pdo, [0, -3, 10, 10, '11']);
        $this->assertSame(['Administrator', 'Bob Helper'], array_column($assignees[10], 'display_name'));
        $this->assertSame(['Zita User'], array_column($assignees[11], 'display_name'));
    }

    public function test_project_assignees_are_grouped_by_project(): void
    {
        $this->seed();
        $assignees = project_assignees_by_project($this->pdo, [20, 21]);
        $this->assertSame(['Administrator', 'Zita User'], array_column($assignees[20], 'display_name'));
        $this->assertArrayNotHasKey(21, $assignees);
        $this->assertSame([], project_assignees_by_project($this->pdo, [0]));
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

    // ---- Saved searches (Task #29) -----------------------------------------

    public function test_saved_search_query_builds_the_canonical_filter_query(): void
    {
        $query = tb_saved_search_query([
            'q' => 'Beta',
            'status' => ['2'],
            'sort' => 'updated',
            'dir' => 'desc',
        ]);
        $this->assertSame('q=Beta&status%5B0%5D=2&sort=updated&dir=desc', $query);
    }

    public function test_saved_search_query_keeps_multi_value_filters(): void
    {
        $query = tb_saved_search_query([
            'assignee' => ['2', '5', 'unassigned'],
            'parent' => ['none'],
        ]);
        $this->assertSame('assignee%5B0%5D=2&assignee%5B1%5D=5&assignee%5B2%5D=unassigned&parent%5B0%5D=none', $query);
    }

    public function test_saved_search_query_normalizes_legacy_scalar_shapes(): void
    {
        // Old bookmarks link ?status=1; the same value through the save form
        // produces the same stored link semantics either way.
        $this->assertSame('status=2', tb_saved_search_query(['status' => '2']));
        $this->assertSame('q=Beta', tb_saved_search_query(['q' => 'Beta']));
    }

    public function test_saved_search_query_collapses_a_crafted_scalar_array_to_its_first_value(): void
    {
        // A hand-crafted q[]=... must never be stored as an array: the list
        // page reads ?q= as a string, so the stored link stays a plain one.
        $this->assertSame('q=Beta&sort=title', tb_saved_search_query(['q' => 'Beta', 'sort' => ['title', 'updated']]));
    }

    public function test_saved_search_query_drops_unknown_empty_and_blank_values(): void
    {
        $query = tb_saved_search_query([
            'q' => 'Beta',
            'status' => ['', null],
            'assignee' => [],
            'csrf' => 'token',
            'action' => 'save_search',
            'page' => 'task',
            'id' => '1',
            'p' => '3',
            'evil' => 'x',
        ]);
        $this->assertSame('q=Beta', $query, 'Only known filter keys survive; csrf/action/pagination and unknown keys are dropped.');
    }

    public function test_saved_search_query_returns_null_without_a_real_filter(): void
    {
        // Sort-only or empty submissions are not a search.
        $this->assertNull(tb_saved_search_query([]));
        $this->assertNull(tb_saved_search_query(['sort' => 'updated', 'dir' => 'asc']));
        $this->assertNull(tb_saved_search_query(['status' => ['']]));
    }

    public function test_saved_search_signature_compares_views_canonically(): void
    {
        // #132: the signature is the comparison form of a list view - unknown
        // and junk keys are dropped, a legacy scalar URL shape equals the
        // stored array shape of the same selection, multi-value keys compare
        // order-insensitively and a one-element array collapses to the scalar.
        $stored = tb_saved_search_view_signature(['q' => 'Beta', 'status' => ['2']]);
        $this->assertSame(['q' => 'Beta', 'status' => '2'], $stored);
        $this->assertSame($stored, tb_saved_search_view_signature(['status' => '2', 'q' => 'Beta', 'evil' => 'x', 'page' => 'kanban', 'p' => '2']), 'Junk and structural keys are dropped; scalar and array shapes of one selection are the same view.');
        $this->assertSame(['assignee' => ['3', '5', 'unassigned']], tb_saved_search_view_signature(['assignee' => ['unassigned', '5', '3', '5']]), 'Multi-value keys compare order-insensitively and de-duplicated.');
        $this->assertSame(['q' => 'Beta', 'sort' => ['title', 'updated'], 'dir' => 'desc'], tb_saved_search_view_signature(['q' => 'Beta', 'sort' => ['title', 'updated'], 'dir' => 'desc']), 'A crafted scalar array stays its own (sorted) shape: the save handler collapses it before storing, but at read time it is a different view, so it must not identify the stored scalar link.');
        $this->assertSame([], tb_saved_search_view_signature(['page' => 'tasks', 'clear_search' => '1']), 'A bare visit has no view signature.');
    }

    public function test_saved_search_selected_names_the_search_the_view_came_from(): void
    {
        // #132: the current list view (with the #113 replay already merged
        // into $_GET) is canonicalized the same way the stored links are, so
        // the first own saved search carrying the same view wins; a view no
        // stored search holds returns null.
        $searches = [
            ['id' => '7', 'name' => 'Beta only', 'query' => 'q=Beta&status%5B0%5D=2'],
            ['id' => '9', 'name' => 'Picks', 'query' => 'assignee%5B0%5D=2&assignee%5B1%5D=5&sort=title&dir=desc'],
        ];
        $_GET = ['page' => 'tasks', 'q' => 'Beta', 'status' => ['2']];
        $this->assertSame('Beta only', tb_saved_search_selected($searches)['name']);
        $_GET = ['page' => 'tasks', 'status' => '2', 'q' => 'Beta', 'evil' => 'x'];
        $this->assertSame('Beta only', tb_saved_search_selected($searches)['name'], 'The legacy scalar URL shape and junk parameters still identify the same search.');
        $_GET = ['page' => 'tasks', 'assignee' => ['5', '2'], 'sort' => 'title', 'dir' => 'desc'];
        $this->assertSame('Picks', tb_saved_search_selected($searches)['name'], 'A different multi-select order is still the same stored search.');
        $_GET = ['page' => 'tasks', 'assignee' => ['5'], 'sort' => 'title', 'dir' => 'desc'];
        $this->assertNull(tb_saved_search_selected($searches), 'A hand-modified filter set matches no stored search.');
        $_GET = ['page' => 'tasks', 'q' => 'Beta', 'status' => ['1']];
        $this->assertNull(tb_saved_search_selected($searches), 'A different filter value matches nothing.');
        $_GET = ['page' => 'tasks'];
        $this->assertNull(tb_saved_search_selected($searches), 'The plain list is nobody\'s search.');
        $_GET = ['page' => 'tasks', 'q' => 'Beta', 'status' => ['2']];
        $this->assertNull(tb_saved_search_selected([]), 'No saved searches, no name.');
        $_GET = ['page' => 'tasks', 'q' => 'Beta', 'status' => ['2']];
        $this->assertNull(tb_saved_search_selected([['id' => '3', 'name' => 'Broken', 'query' => '']]), 'A broken stored link matches nothing instead of fataling.');
        $_GET = [];
    }

    public function test_filter_query_value_reads_the_given_source_instead_of_get(): void
    {
        $_GET['status'] = '1';
        $this->assertSame('2', filter_query_value('status', ['status' => '2']), 'An explicit source wins over $_GET.');
        $this->assertSame('1', filter_query_value('status'), 'Without a source the helpers keep reading $_GET.');
        $this->assertSame(['3', '4'], filter_query_value('status', ['status' => ['3', '4']]));
        $this->assertNull(filter_query_value('missing', ['status' => '2']));
        $this->assertNull(filter_query_value('blank', ['blank' => '']));
        unset($_GET['status']);
    }

    public function test_task_filters_where_reports_the_selected_status_ids(): void
    {
        // #105: statusIds is the exact id list the WHERE clause filtered on,
        // so a view can align its presentation with the query (the kanban
        // board renders only the selected status columns from it).
        // The file's earlier tests leave $_GET dirty, so this one starts from
        // a clean slate to pin the status ids on their own.
        $_GET = [];
        $_GET['status'] = ['3', 'garbage', '1', '3'];
        $filters = tb_task_filters_where();
        $this->assertSame([3, 1], $filters['statusIds'], 'Only the valid, de-duplicated status ids are reported, in first-seen order.');
        $this->assertContains('t.status_id IN (?,?)', $filters['where'], 'The WHERE clause filters on the same ids.');
        $this->assertSame([3, 1], $filters['params'], 'The same ids are bound as parameters.');

        $_GET = [];
        $this->assertSame([], tb_task_filters_where()['statusIds'], 'An empty status filter reports no ids - every status stays visible.');

        $_GET['status'] = ['garbage'];
        $this->assertSame([], tb_task_filters_where()['statusIds'], 'A garbage value is ignored, exactly like the WHERE clause ignores it.');

        $_GET['status'] = ['0', '-2', '99999999999999'];
        $this->assertSame([], tb_task_filters_where()['statusIds'], 'Non-positive and out-of-range values are ignored like the WHERE clause does.');
        unset($_GET['status']);
    }
}
