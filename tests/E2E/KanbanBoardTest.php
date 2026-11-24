<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #43: a kanban board page — one column per task status, every
 * non-archived task listed in its status column, with the task list's filter
 * card and saved searches on top. The filter markup (tb_render_task_filter_card)
 * and the WHERE building (tb_task_filters_where) are shared with the task
 * list, so a filter or saved search behaves identically on both views.
 */
final class KanbanBoardTest extends E2eTestCase
{
    /** Creates a project through the real form and returns its id. */
    private function createProject(AppSession $session, string $name): int
    {
        $session->get('/?page=project_new');
        $response = $session->post('/?page=project_new', [
            'action' => 'save_project', 'id' => '0', 'name' => $name,
            'description' => 'Created by the test suite', 'status_id' => '1', 'assignees' => [],
        ]);
        if (!preg_match('/page=project&id=(\d+)/', (string)$response->header('location'), $m)) {
            throw new \RuntimeException('Project creation did not redirect to the project page.');
        }
        return (int)$m[1];
    }

    private function board(AppSession $session, string $suffix = ''): string
    {
        return $session->get('/?page=kanban' . $suffix)->body;
    }

    private function columnHeader(string $statusName): string
    {
        // #66: the header groups the status badge and the task-count badge in
        // one inner div and carries the collapse-toggle form next to them.
        return '<div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2">'
            . '<div class="d-flex align-items-center gap-2"><span class="badge text-bg-light border">' . $statusName . '</span>';
    }

    private function taskCardLink(int $taskId, string $title): string
    {
        return 'href="?page=task&id=' . $taskId . '">#' . $taskId . ' · ' . $title . '</a>';
    }

    public function test_kanban_shows_one_column_per_status_with_tasks_in_their_column(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => 'REVIEW']);
        $reviewId = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='REVIEW'")->fetchColumn();

        $todoTask = $admin->createTask('Todo item');
        $doneTask = $admin->createTask('Done item', ['status_id' => '3']);
        $reviewTask = $admin->createTask('Review item', ['status_id' => (string)$reviewId]);

        $body = $this->board($admin);

        // One column per status, in display order.
        foreach (['TODO', 'IN PROGRESS', 'DONE', 'REVIEW'] as $status) {
            $this->assertNotFalse(strpos($body, $this->columnHeader($status)), 'The ' . $status . ' column must be rendered.');
        }
        $this->assertTrue(
            strpos($body, $this->columnHeader('TODO')) < strpos($body, $this->columnHeader('IN PROGRESS'))
            && strpos($body, $this->columnHeader('IN PROGRESS')) < strpos($body, $this->columnHeader('DONE'))
            && strpos($body, $this->columnHeader('DONE')) < strpos($body, $this->columnHeader('REVIEW')),
            'The columns follow the status display order.'
        );

        // Every task sits in its own status column: each card link comes after
        // its column header and before the next column's header.
        $todoPos = strpos($body, $this->taskCardLink($todoTask, 'Todo item'));
        $donePos = strpos($body, $this->taskCardLink($doneTask, 'Done item'));
        $reviewPos = strpos($body, $this->taskCardLink($reviewTask, 'Review item'));
        $this->assertTrue($todoPos > strpos($body, $this->columnHeader('TODO')) && $todoPos < strpos($body, $this->columnHeader('IN PROGRESS')), 'The TODO task is in the TODO column.');
        $this->assertTrue($donePos > strpos($body, $this->columnHeader('DONE')) && $donePos < strpos($body, $this->columnHeader('REVIEW')), 'The DONE task is in the DONE column.');
        $this->assertTrue($reviewPos > strpos($body, $this->columnHeader('REVIEW')), 'The REVIEW task is in the REVIEW column.');

        // Empty columns say so instead of disappearing.
        $inProgress = substr($body, strpos($body, $this->columnHeader('IN PROGRESS')));
        $this->assertStringContainsString('No tasks', (string)substr($inProgress, 0, (int)strpos($inProgress, $this->columnHeader('DONE'))));
    }

    public function test_archived_tasks_do_not_appear_on_the_kanban_board(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Vanishing item');
        $admin->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId, 'return' => '?']);

        $body = $this->board($admin);
        $this->assertStringNotContainsString('Vanishing item', $body, 'Archived tasks belong to the archive only.');
        $this->assertStringContainsString('No tasks', $body, 'The TODO column renders empty.');
    }

    public function test_the_status_filter_shows_only_the_selected_columns(): void
    {
        // #105: the status filter acts as a selection of which groups stay
        // visible - a status that is not picked disappears from the board
        // together with its empty "No tasks" group, instead of rendering a
        // column that can never hold a task under the current filter.
        $admin = $this->adminSession();
        $todo = $admin->createTask('Todo item');
        $progress = $admin->createTask('Progress item', ['status_id' => '2']);
        $done = $admin->createTask('Done item', ['status_id' => '3']);

        // One picked status: only that column renders, with its task inside.
        $todoOnly = $this->board($admin, '&status[]=1');
        $this->assertStringContainsString($this->columnHeader('TODO'), $todoOnly, 'The selected status column renders.');
        $this->assertStringContainsString($this->taskCardLink($todo, 'Todo item'), $todoOnly);
        $this->assertStringNotContainsString($this->columnHeader('IN PROGRESS'), $todoOnly, 'The unselected IN PROGRESS column is gone from the board.');
        $this->assertStringNotContainsString($this->columnHeader('DONE'), $todoOnly, 'The unselected DONE column is gone from the board.');
        $this->assertStringNotContainsString($this->taskCardLink($progress, 'Progress item'), $todoOnly, 'Tasks of unselected statuses are not on the board.');
        $this->assertStringNotContainsString($this->taskCardLink($done, 'Done item'), $todoOnly, 'Tasks of unselected statuses are not on the board.');
        $this->assertStringContainsString('across 1 status column', $todoOnly, 'The header counts only the rendered columns.');
        $this->assertStringContainsString('value="1" selected', $todoOnly, 'The picked status stays marked in the filter dropdown.');

        // Two picked statuses: both columns render in display order, the third stays hidden.
        $two = $this->board($admin, '&status[]=1&status[]=3');
        $this->assertStringContainsString($this->columnHeader('TODO'), $two);
        $this->assertStringContainsString($this->columnHeader('DONE'), $two);
        $this->assertStringNotContainsString($this->columnHeader('IN PROGRESS'), $two);
        $this->assertTrue(
            strpos($two, $this->columnHeader('TODO')) < strpos($two, $this->columnHeader('DONE')),
            'The rendered columns keep the status display order.'
        );
        $this->assertStringContainsString('across 2 status columns', $two, 'The header counts only the rendered columns.');

        // Without a status filter every column is back, as before.
        $all = $this->board($admin);
        foreach (['TODO', 'IN PROGRESS', 'DONE'] as $status) {
            $this->assertStringContainsString($this->columnHeader($status), $all, 'The ' . $status . ' column is back without a status filter.');
        }
        $this->assertStringContainsString($this->taskCardLink($progress, 'Progress item'), $all);
    }

    public function test_kanban_filters_narrow_the_cards(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Kanban project');
        $inProject = $admin->createTask('Project item');
        $admin->get('/?page=edit&id=' . $inProject);
        $admin->post('/?page=edit&id=' . $inProject, [
            'action' => 'save_task', 'id' => (string)$inProject, 'title' => 'Project item',
            'description' => 'Created by the test suite', 'status_id' => '1',
            'parent_id' => '', 'project_id' => (string)$projectId, 'assignees' => [],
        ]);
        $other = $admin->createTask('Unrelated item');

        // The q search narrows the board.
        $searched = $this->board($admin, '&q=Project+item');
        $this->assertStringContainsString($this->taskCardLink($inProject, 'Project item'), $searched);
        $this->assertStringNotContainsString('Unrelated item', $searched);

        // The project filter narrows the board and keeps the selected option marked.
        $filtered = $this->board($admin, '&project[]=' . $projectId);
        $this->assertStringContainsString($this->taskCardLink($inProject, 'Project item'), $filtered);
        $this->assertStringNotContainsString('Unrelated item', $filtered);
        $this->assertStringContainsString('value="' . $projectId . '" selected', $filtered);

        // The header still links a way out of the filter (same as the task list).
        $this->assertStringContainsString('Unrelated item', $this->board($admin), 'Without filters every task is back.');
    }

    public function test_kanban_cards_show_the_task_priority(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Priority item');
        $admin->get('/?page=edit&id=' . $taskId);
        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Priority item',
            'description' => 'Created by the test suite', 'status_id' => '1',
            'parent_id' => '', 'priority' => '7', 'assignees' => [],
        ]);

        // Every card carries the x-priority badge right after the title link -
        // the same mark the task list's Priority column uses (#45), now on the
        // kanban board too (#72).
        $body = $this->board($admin);
        $this->assertStringContainsString(
            '#' . $taskId . ' · Priority item</a><span class="badge text-bg-light border" title="Priority">&times;7</span>',
            $body,
            'The card shows the task priority next to the title.'
        );

        // A task left at the default priority shows x1, not nothing.
        $default = $admin->createTask('Default priority item');
        $body = $this->board($admin);
        $this->assertStringContainsString(
            '#' . $default . ' · Default priority item</a><span class="badge text-bg-light border" title="Priority">&times;1</span>',
            $body
        );
    }

    public function test_kanban_shares_the_filter_and_saved_search_ui(): void
    {
        $admin = $this->adminSession();
        $body = $this->board($admin);
        $this->assertStringContainsString('name="action" value="save_search"', $body, 'The save-search form is on the board.');
        $this->assertStringContainsString('id="filter-status"', $body, 'The status filter dropdown is on the board.');
        $this->assertStringContainsString('name="page" value="kanban"', $body, 'The filter form submits back to the kanban page.');
        $this->assertStringContainsString('No saved searches yet.', $body);

        // Saving a search from the board stores the filters and returns to the board.
        $response = $admin->post('/?page=kanban', [
            'action' => 'save_search', 'name' => 'Board alpha', 'q' => 'Alpha', 'save_page' => 'kanban',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=kanban&q=Alpha', (string)$response->header('location'), 'The redirect is the stored search link on the kanban page.');

        // The dropdown on the board opens the stored link on the same page kind.
        $board = $this->board($admin);
        $this->assertStringContainsString('Board alpha', $board);
        $this->assertStringContainsString('href="?page=kanban&amp;q=Alpha"', $board, 'The saved search opens on the kanban page.');

        // The same saved search is visible on the task list, opening there (shared storage).
        $list = $admin->get('/?page=tasks');
        $this->assertStringContainsString('href="?page=tasks&amp;q=Alpha"', $list->body, 'The saved search opens on the task list from there.');

        // Opening the stored kanban link applies the filter.
        $open = $admin->get('/?page=kanban&q=Alpha');
        $this->assertSame(200, $open->status);
        $this->assertStringContainsString('value="Alpha"', $open->body, 'The q filter is echoed back into the search box.');
    }

    public function test_deleting_a_saved_search_returns_to_the_kanban(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Alpha item');
        $admin->post('/?page=kanban', [
            'action' => 'save_search', 'name' => 'Board alpha', 'q' => 'Alpha', 'save_page' => 'kanban',
        ]);
        $id = (int)TestServer::pdo()->query("SELECT id FROM saved_searches WHERE name='Board alpha'")->fetchColumn();

        $response = $admin->post('/?page=kanban', [
            'action' => 'delete_search', 'id' => (string)$id, 'return' => '?page=kanban&q=Alpha',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=kanban&q=Alpha', (string)$response->header('location'), 'The delete returns to the board it was triggered from.');
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM saved_searches')->fetchColumn());
    }

    public function test_kanban_is_available_to_regular_users_and_in_the_menu(): void
    {
        $admin = $this->adminSession();
        $this->assertStringContainsString('href="?page=kanban">Kanban</a>', $admin->get('/?')->body, 'The navbar links the board.');

        $admin->post('/?page=users', [
            'action' => 'add_user', 'username' => 'worker',
            'display_name' => 'Worker', 'password' => 'WorkerPass1234', 'role' => 'user',
        ]);
        $worker = new AppSession('worker', 'WorkerPass1234');
        $worker->createTask('Worker item');
        $board = $worker->get('/?page=kanban');
        $this->assertSame(200, $board->status, 'The board is a task view, not an admin page.');
        $this->assertStringContainsString('Worker item', $board->body);
    }

    public function test_the_kanban_board_renders_without_php_warnings(): void
    {
        // #91: the 66v2 merge applied the collapsed-preference flags before the
        // column rebuild defined $kanbanColumns, so every board view printed
        // "Undefined variable $kanbanColumns" and "foreach() argument must be
        // of type array|object, null given" above the page. The release fix
        // (#65 ci-fix) builds the columns first; this pin keeps any future
        // reordering of that section from leaking PHP warnings into the board.
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Warning-free item');

        $body = $this->board($admin);
        $this->assertStringContainsString(
            $this->taskCardLink($taskId, 'Warning-free item'),
            $body,
            'The board rendered with its cards - the pins below are not green on an empty page.'
        );
        $this->assertStringNotContainsString('Warning:', $body, 'No PHP warning may leak into the rendered board.');
        $this->assertStringNotContainsString('Undefined variable', $body, 'The kanban variables must be defined before anything renders.');
        $this->assertStringNotContainsString('foreach() argument', $body, 'A foreach over an undefined variable must never reach the page.');
    }
}
