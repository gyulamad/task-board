<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

/**
 * Task #154: the kanban board cards are native HTML5 draggables and every
 * column body is a drop zone - a drop changes the card's status (across
 * columns) or its order inside the column. The JS only places the card and
 * submits the column's new DOM order; the move_kanban_task action does the
 * real work server-side: the submitted visible order ranks 1..N, the hidden
 * (filtered-out) tasks of the column follow appended in their previous board
 * order, the status move writes the same audit rows the #153 quick change
 * writes, the reorder writes a kanban_order row with human-readable
 * positions, and a drop that changes nothing writes nothing at all.
 */
final class KanbanDragDropTest extends E2eTestCase
{
    private function board(string $suffix = ''): string
    {
        return $this->session->get('/?page=kanban' . $suffix)->body;
    }

    private function columnHeader(string $statusName): string
    {
        return '<div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2">'
            . '<div class="d-flex align-items-center gap-2"><span class="badge text-bg-light border">' . $statusName . '</span>';
    }

    private function taskCardLink(int $taskId, string $title): string
    {
        return 'href="?page=task&id=' . $taskId . '">#' . $taskId . ' · ' . $title . '</a>';
    }

    private function ranks(): array
    {
        $rows = TestServer::pdo()->query('SELECT id, status_id, kanban_rank FROM tasks ORDER BY id')->fetchAll();
        $ranks = [];
        foreach ($rows as $row) $ranks[(int)$row['id']] = [(int)$row['status_id'], (int)$row['kanban_rank']];
        return $ranks;
    }

    public function test_the_board_renders_draggable_cards_and_drop_zone_columns(): void
    {
        $this->adminSession();
        $taskId = $this->session->createTask('Draggable item');

        $body = $this->board();
        $this->assertStringContainsString('draggable="true" data-task-id="' . $taskId . '" data-tb-kanban-card', $body, 'The cards are HTML5 draggables carrying their task id.');
        $this->assertStringContainsString('data-status-id="1" data-tb-kanban-column', $body, 'The column bodies are drop zones carrying their status id.');
        $this->assertStringContainsString("field('csrf',(document.querySelector('input[name=\"csrf\"]')||{}).value||'')", $body, 'The drop form reuses the page session CSRF token.');
        $this->assertStringContainsString("field('action','move_kanban_task')", $body, 'The drop script posts the move_kanban_task action.');
        $this->assertStringContainsString('.tb-kanban-dragging{opacity:.45}', $body, 'The dragging visual is defined.');
        $this->assertStringContainsString('Drag a card onto another column', $body, 'The footer hint explains the gesture.');
        // The collapse toggle (pre-#154 behaviour) stays untouched.
        $this->assertStringContainsString('name="action" value="toggle_kanban_column"', $body);
    }

    public function test_a_drop_into_another_column_moves_the_task_with_the_full_audit_trail(): void
    {
        $this->adminSession();
        $taskId = $this->session->createTask('Moving item');

        $response = $this->session->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '2', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(302, $response->status, 'The drop redirects back to the board.');
        $this->assertStringContainsString('page=kanban', (string)$response->header('location'));

        $ranks = $this->ranks();
        $this->assertSame([2, 1], $ranks[$taskId], 'The task moved to IN PROGRESS with rank 1.');

        $page = $this->session->get('/?page=task&id=' . $taskId);
        $this->assertStringContainsString('Kanban board updated.', $page->body, 'The confirmation ribbon renders.');
        $this->assertStringContainsString('The followings are changed: Status. Status changed from &quot;TODO&quot; to &quot;IN PROGRESS&quot;.', $page->body, 'The event sentence matches the full save shape.');
        $this->assertStringContainsString('Field &quot;status&quot;: from &quot;TODO&quot; to &quot;IN PROGRESS&quot;', $page->body, 'The status audit row renders.');
        $this->assertStringNotContainsString('Field &quot;kanban_order&quot;', $page->body, 'A 1-to-1 column move changes no position, so no kanban_order row exists.');
    }

    public function test_a_reorder_inside_a_column_ranks_the_column_and_writes_the_kanban_order_row(): void
    {
        $this->adminSession();
        $first = $this->session->createTask('First item');
        $second = $this->session->createTask('Second item');

        $response = $this->session->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$first, 'status_id' => '1', 'order' => [(string)$second, (string)$first],
        ]);
        $this->assertSame(302, $response->status);
        $ranks = $this->ranks();
        $this->assertSame([1, 2], $ranks[$second], 'The second card now leads the column.');
        $this->assertSame([1, 1], $ranks[$first], 'The dragged card follows it.');

        $body = $this->board();
        $this->assertTrue(
            strpos($body, $this->taskCardLink($second, 'Second item')) < strpos($body, $this->taskCardLink($first, 'First item')),
            'The board renders the manual order.'
        );

        $page = $this->session->get('/?page=task&id=' . $first);
        $this->assertStringContainsString('The followings are changed: Kanban order. Kanban order changed from &quot;position 1 in TODO&quot; to &quot;position 2 in TODO&quot;.', $page->body, 'The reorder sentence names both positions.');
        $rows = TestServer::pdo()->query("SELECT field, old_value, new_value FROM task_history WHERE task_id=$first AND field IS NOT NULL ORDER BY id")->fetchAll();
        $this->assertCount(1, $rows, 'Only the kanban_order field row - a pure reorder writes no status row.');
        $this->assertSame('kanban_order', $rows[0]['field']);
        $this->assertSame('position 1 in TODO', $rows[0]['old_value']);
        $this->assertSame('position 2 in TODO', $rows[0]['new_value']);
    }

    public function test_a_drop_that_changes_nothing_writes_no_history_row(): void
    {
        $this->adminSession();
        $taskId = $this->session->createTask('Steady item');

        $before = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $response = $this->session->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '1', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->get('/?page=kanban');
        $this->assertStringContainsString('The kanban board did not change.', $page->body, 'The no-op says so honestly.');
        $after = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $this->assertSame($before, $after, 'A no-op drop writes no row.');
    }

    public function test_a_drop_under_filters_appends_the_hidden_tasks_after_the_arranged_row(): void
    {
        $this->adminSession();
        $workerId = $this->createWorker('worker', 'Worker User', 'WorkerPass12345');
        $moved = $this->session->createTask('Filtered move', ['assignees' => ['1']]);
        $visible = $this->session->createTask('Visible peer', ['status_id' => '2', 'assignees' => ['1']]);
        $hidden = $this->session->createTask('Hidden peer', ['status_id' => '2', 'assignees' => [(string)$workerId]]);

        // The assignee filter shows only the admin-assigned cards, so the
        // submitted IN PROGRESS order can only name the visible peer - the
        // worker's card stays hidden and must not interleave.
        $response = $this->session->post('/?page=kanban&assignee=1', [
            'action' => 'move_kanban_task', 'id' => (string)$moved, 'status_id' => '2', 'order' => [(string)$visible],
        ]);
        $this->assertSame(302, $response->status);
        $ranks = $this->ranks();
        $this->assertSame([2, 1], $ranks[$visible], 'The visible peer keeps the arranged first position.');
        $this->assertSame([2, 2], $ranks[$moved], 'The dragged card lands right behind it.');
        $this->assertSame([2, 3], $ranks[$hidden], 'The hidden peer follows appended - never interleaved into the arranged row.');
    }

    public function test_an_archived_task_is_refused_by_the_move_action(): void
    {
        $this->adminSession();
        $taskId = $this->session->createTask('Archived move');
        $this->session->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId, 'return' => '?']);

        $response = $this->session->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '2', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('This task is archived and read-only. Restore it from the archive first.', $response->body);
        $this->assertSame([1, 0], $this->ranks()[$taskId], 'The archived task keeps its status and rank.');
    }

    public function test_the_move_action_refuses_an_unknown_status_and_a_malformed_order(): void
    {
        $this->adminSession();
        $taskId = $this->session->createTask('Bad move');

        $response = $this->session->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '999', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('alert alert-danger', $response->body);
        $this->assertStringContainsString('Choose a valid status.', $response->body);
        $this->assertSame([1, 0], $this->ranks()[$taskId]);

        // Archived ids in a crafted order list are dropped, not ranked: the
        // move itself still lands for the live task.
        $peer = $this->session->createTask('Live peer');
        $this->session->post('/?page=task&id=' . $peer, ['action' => 'archive_task', 'id' => (string)$peer, 'return' => '?']);
        $response = $this->session->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '2', 'order' => [(string)$peer, (string)$taskId],
        ]);
        $this->assertSame(302, $response->status);
        $ranks = $this->ranks();
        $this->assertSame([2, 1], $ranks[$taskId], 'The live task took the first real position.');
        $this->assertSame(1, $ranks[$peer][0], 'The archived peer is not moved by a crafted list.');
    }

    public function test_the_move_action_refuses_an_unknown_task(): void
    {
        $this->adminSession();
        $response = $this->session->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => '9999', 'status_id' => '2', 'order' => ['9999'],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Task not found.', $response->body);
    }

    public function test_fresh_installs_carry_the_kanban_rank_column(): void
    {
        $this->adminSession();
        $pdo = TestServer::pdo();
        $isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $names = $isMysql
            ? array_map(fn(array $c): string => (string)$c['Field'], $pdo->query('SHOW COLUMNS FROM tasks')->fetchAll(\PDO::FETCH_ASSOC))
            : array_map(fn(array $c): string => (string)$c['name'], $pdo->query('PRAGMA table_info(tasks)')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertContains('kanban_rank', $names, 'schema.sql creates the kanban_rank column (and the boot-time self-heal adds it on upgraded boards).');
    }

    private function createWorker(string $username, string $display, string $password): int
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user', 'username' => $username,
            'display_name' => $display, 'password' => $password, 'role' => 'user',
        ]);
        $id = (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='" . $username . "'")->fetchColumn();
        $this->assertGreaterThan(0, $id, 'The test user was created.');
        return $id;
    }
}
