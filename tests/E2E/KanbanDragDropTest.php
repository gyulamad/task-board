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
 * positions, and a drop that changes nothing writes nothing at all. Since
 * #175 the move redirects back to the very URL the drop came from, so the
 * board's active filter survives every drag.
 */
final class KanbanDragDropTest extends E2eTestCase
{
    private function board(string $suffix = ''): string
    {
        return $this->adminSession()->get('/?page=kanban' . $suffix)->body;
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
        $taskId = $this->adminSession()->createTask('Draggable item');

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

    public function test_the_board_ships_the_touch_drag_path_for_phones_and_tablets(): void
    {
        // #154 v2: the native HTML5 drag never fires on touch devices, so the
        // board ships a pointer/touch path beside it - press and hold picks the
        // card up, the card follows the finger, the column under it lights up
        // and the same move_kanban_task form is submitted on lift. The mouse
        // keeps the HTML5 path, so desktop behaviour is unchanged.
        $this->adminSession();
        $body = $this->board();
        $this->assertStringContainsString("e.pointerType==='mouse')return", $body, 'The mouse keeps the native HTML5 drag path.');
        $this->assertStringContainsString('PICK_DELAY', $body, 'The press-and-hold pick-up delay is defined.');
        $this->assertStringContainsString("field('action','move_kanban_task')", $body, 'The touch drop submits the same move_kanban_task action.');
        $this->assertStringContainsString("field('status_id',column.getAttribute('data-status-id')||'')", $body, 'The touch drop carries the target column status id.');
        $this->assertStringContainsString('.tb-kanban-over{outline:2px dashed', $body, 'The column highlight visual is defined.');
        $this->assertStringContainsString(".tb-kanban-noselect{-webkit-touch-callout:none", $body, 'The pick-up suppresses the long-press callout.');
        $this->assertStringContainsString('{passive:false}', $body, 'The touchmove scroll blocker is registered non-passive.');
        $this->assertStringContainsString('press and hold a card', $body, 'The footer hint explains the touch gesture.');
    }

    public function test_a_drop_into_another_column_moves_the_task_with_the_full_audit_trail(): void
    {
        $this->adminSession();
        $taskId = $this->adminSession()->createTask('Moving item');

        $response = $this->adminSession()->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '2', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(302, $response->status, 'The drop redirects back to the board.');
        $this->assertStringContainsString('page=kanban', (string)$response->header('location'));

        $ranks = $this->ranks();
        $this->assertSame([2, 1], $ranks[$taskId], 'The task moved to IN PROGRESS with rank 1.');

        $page = $this->adminSession()->get('/?page=task&id=' . $taskId);
        $this->assertStringContainsString('Kanban board updated.', $page->body, 'The confirmation ribbon renders.');
        $this->assertStringContainsString('The followings are changed: Status. Status changed from &quot;TODO&quot; to &quot;IN PROGRESS&quot;.', $page->body, 'The event sentence matches the full save shape.');
        $this->assertStringContainsString('Field &quot;status&quot;: from &quot;TODO&quot; to &quot;IN PROGRESS&quot;', $page->body, 'The status audit row renders.');
        $this->assertStringNotContainsString('Field &quot;kanban_order&quot;', $page->body, 'A 1-to-1 column move changes no position, so no kanban_order row exists.');
    }

    public function test_a_reorder_inside_a_column_ranks_the_column_and_writes_the_kanban_order_row(): void
    {
        $this->adminSession();
        $first = $this->adminSession()->createTask('First item');
        $second = $this->adminSession()->createTask('Second item');

        // Pre-drop the column renders in its updated_at DESC order, so the
        // newer Second item sits at position 1 and the First item at position
        // 2. The drop lifts the First card above it: the submitted column
        // order (the same order the #154 drop JS collects from the DOM)
        // ranks 1..N, so the dragged card takes position 1 and the reorder
        // writes the kanban_order audit row naming both positions.
        $response = $this->adminSession()->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$first, 'status_id' => '1', 'order' => [(string)$first, (string)$second],
        ]);
        $this->assertSame(302, $response->status);
        $ranks = $this->ranks();
        $this->assertSame([1, 1], $ranks[$first], 'The dragged card now leads the column.');
        $this->assertSame([1, 2], $ranks[$second], 'The second card follows it.');

        $body = $this->board();
        $this->assertTrue(
            strpos($body, $this->taskCardLink($first, 'First item')) < strpos($body, $this->taskCardLink($second, 'Second item')),
            'The board renders the manual order.'
        );

        // The reorder's audit, read straight from the table: one field row
        // naming both positions (the rendered-sentence shape is pinned by the
        // cross-column move test above).
        $rows = TestServer::pdo()->query("SELECT field, old_value, new_value FROM task_history WHERE task_id=$first AND field IS NOT NULL ORDER BY id")->fetchAll();
        $this->assertCount(1, $rows, 'Only the kanban_order field row - a pure reorder writes no status row.');
        $this->assertSame('kanban_order', $rows[0]['field']);
        $this->assertSame('position 2 in TODO', $rows[0]['old_value']);
        $this->assertSame('position 1 in TODO', $rows[0]['new_value']);
    }

    public function test_a_drop_that_changes_nothing_writes_no_history_row(): void
    {
        $this->adminSession();
        $taskId = $this->adminSession()->createTask('Steady item');

        $before = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $response = $this->adminSession()->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '1', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->adminSession()->get('/?page=kanban');
        $this->assertStringContainsString('The kanban board did not change.', $page->body, 'The no-op says so honestly.');
        $after = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $this->assertSame($before, $after, 'A no-op drop writes no row.');
    }

    public function test_a_drop_under_filters_appends_the_hidden_tasks_after_the_arranged_row(): void
    {
        $this->adminSession();
        $workerId = $this->createWorker('worker', 'Worker User', 'WorkerPass12345');
        $moved = $this->adminSession()->createTask('Filtered move', ['assignees' => ['1']]);
        $visible = $this->adminSession()->createTask('Visible peer', ['status_id' => '2', 'assignees' => ['1']]);
        $hidden = $this->adminSession()->createTask('Hidden peer', ['status_id' => '2', 'assignees' => [(string)$workerId]]);

        // The assignee filter shows only the admin-assigned cards, so the
        // submitted IN PROGRESS order can only name the visible peer - the
        // worker's card stays hidden and must not interleave.
        $response = $this->adminSession()->post('/?page=kanban&assignee=1', [
            'action' => 'move_kanban_task', 'id' => (string)$moved, 'status_id' => '2', 'order' => [(string)$visible],
        ]);
        $this->assertSame(302, $response->status);
        $ranks = $this->ranks();
        $this->assertSame([2, 1], $ranks[$visible], 'The visible peer keeps the arranged first position.');
        $this->assertSame([2, 2], $ranks[$moved], 'The dragged card lands right behind it.');
        $this->assertSame([2, 3], $ranks[$hidden], 'The hidden peer follows appended - never interleaved into the arranged row.');
    }

    public function test_a_drop_returns_the_visitor_to_the_filtered_board_they_dragged_on(): void
    {
        // #175: kanban drag drop to another column clears the filter. The drop
        // posts to the current URL, but the move action redirected to the bare
        // ?page=kanban - a consecutive bare visit the #113 replay rules never
        // restore - so every drag silently dropped the active filter. The move
        // now redirects to the very URL the drop came from, filters included.
        // The admin session is taken from adminSession() directly, so this
        // case works on trees without the #172 test-side session fixes.
        $admin = $this->adminSession();
        $moved = $admin->createTask('Filtered drag alpha');
        $other = $admin->createTask('Filtered drag beta');

        $response = $admin->post('/?page=kanban&q=alpha', [
            'action' => 'move_kanban_task', 'id' => (string)$moved, 'status_id' => '2', 'order' => [(string)$moved],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('/?page=kanban&q=alpha', (string)$response->header('location'), 'The move redirects back to the filtered board, not the plain one.');

        $body = $admin->get('/?page=kanban&q=alpha')->body;
        $this->assertStringContainsString($this->taskCardLink($moved, 'Filtered drag alpha'), $body, 'The moved card renders on the returned board.');
        $this->assertStringContainsString('Kanban board updated.', $body, 'The confirmation ribbon renders on the filtered board.');
        $this->assertStringNotContainsString($this->taskCardLink($other, 'Filtered drag beta'), $body, 'The q filter survived the drop - the non-matching card stays hidden.');
    }

    public function test_the_no_change_drop_keeps_the_filter_too(): void
    {
        // #175: the no-op exit used the same bare redirect, so even a drop that
        // changed nothing cleared the filter. It returns to the filtered board
        // as well, with its honest ribbon.
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Steady filtered card');

        $response = $admin->post('/?page=kanban&q=steady', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '1', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('/?page=kanban&q=steady', (string)$response->header('location'), 'The no-change move redirects back to the filtered board.');
        $this->assertStringContainsString('The kanban board did not change.', $admin->get('/?page=kanban&q=steady')->body);
    }

    public function test_a_drop_round_trip_keeps_the_advanced_panel_closed_when_it_was_closed(): void
    {
        // #175 round 2 (the 12:24 report): "Also the filter opens (which is
        // wrong, it should stay closed)". The v1 fix redirects to the very URL
        // the drop came from, but that explicit request carries the advanced
        // keys - so the #48 rule popped the panel open on every drag, even
        // though the visitor dragged with it closed. The drop form now appends
        // adv=1/0 (the panel state the visitor dragged on) and the filter card
        // pins the area state to an explicit adv before the #48/#119 guesses.
        $admin = $this->adminSession();
        $moved = $admin->createTask('Panel closed drag', ['assignees' => ['1']]);
        // The peer must NOT match the assignee=1 filter - the 12:24 report's
        // "non-matching card stays hidden" is only true for a card outside the
        // filter. The v2 authoring fixture assigned it user 1 as well, which
        // made the card legitimately visible and its own pin impossible
        // (caught by the release suite run, documented on the task).
        $other = $admin->createTask('Panel closed peer');

        $response = $admin->post('/?page=kanban&assignee=1&adv=0', [
            'action' => 'move_kanban_task', 'id' => (string)$moved, 'status_id' => '2', 'order' => [(string)$moved],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('/?page=kanban&assignee=1&adv=0', (string)$response->header('location'), 'The redirect rides the same URL including the pinned panel state.');

        $body = $admin->get('/?page=kanban&assignee=1&adv=0')->body;
        $this->assertStringContainsString('<div class="collapse" id="advanced-filters">', $body, 'The advanced area stays closed - the state the visitor dragged on.');
        $this->assertStringNotContainsString('<div class="collapse show" id="advanced-filters">', $body, 'No auto-expansion on the returned board.');
        $this->assertStringContainsString('<span class="badge text-bg-info ms-1">on</span>', $body, 'The toggle keeps its honest "on" badge - the filter itself still applies (#119 idea).');
        $this->assertStringContainsString($this->taskCardLink($moved, 'Panel closed drag'), $body, 'The moved card renders - the assignee filter survived.');
        $this->assertStringNotContainsString($this->taskCardLink($other, 'Panel closed peer'), $body, 'The non-matching card stays hidden.');

        // The documented #48 default is untouched: a request that carries the
        // advanced keys WITHOUT the explicit adv still expands the area.
        $plain = $admin->post('/?page=kanban&assignee=1', [
            'action' => 'move_kanban_task', 'id' => (string)$moved, 'status_id' => '2', 'order' => [(string)$moved],
        ]);
        $this->assertSame(302, $plain->status);
        $this->assertSame('/?page=kanban&assignee=1', (string)$plain->header('location'));
        $body = $admin->get('/?page=kanban&assignee=1')->body;
        $this->assertStringContainsString('<div class="collapse show" id="advanced-filters">', $body, 'No adv param: the #48 rule (expand on an explicit filtered request) still holds.');
    }

    public function test_a_drop_round_trip_with_the_panel_open_keeps_it_open(): void
    {
        // The mirror case: a visitor dragging with the panel open must not see
        // it collapse on the returned board - adv=1 pins it open.
        $admin = $this->adminSession();
        $moved = $admin->createTask('Panel open drag', ['assignees' => ['1']]);

        $response = $admin->post('/?page=kanban&assignee=1&adv=1', [
            'action' => 'move_kanban_task', 'id' => (string)$moved, 'status_id' => '2', 'order' => [(string)$moved],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('/?page=kanban&assignee=1&adv=1', (string)$response->header('location'));

        $body = $admin->get('/?page=kanban&assignee=1&adv=1')->body;
        $this->assertStringContainsString('<div class="collapse show" id="advanced-filters">', $body, 'The advanced area stays open - the state the visitor dragged on.');
    }

    public function test_a_replayed_stored_view_remembers_the_panel_state(): void
    {
        // The #113 stored view captures every view param - adv now rides along,
        // so a later bare visit replays the panel state too. The explicit adv
        // wins over the #119 replay rule in BOTH directions: an adv=1 stored
        // view opens the area on the replayed board (the pre-fix replay rule
        // would have kept it closed and the open state would have been lost).
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Replay panel drag', ['assignees' => ['1']]);

        // A no-op drop is enough: the capture refreshes the stored view with
        // assignee=1 + adv=1.
        $response = $admin->post('/?page=kanban&assignee=1&adv=1', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '1', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(302, $response->status);

        $admin->get('/?page=tasks');
        $body = $admin->get('/?page=kanban')->body;
        $this->assertStringContainsString('<div class="collapse show" id="advanced-filters">', $body, 'The replayed view pins the panel open - the explicit adv beats the #119 replay rule.');
    }

    public function test_the_drop_scripts_carry_the_scroll_and_panel_state_fixes(): void
    {
        // The scroll half of the 12:24 report: "when I drag a task the whole
        // page refreshes jumping to the top so it's no longer there what I
        // want to see". The E2E harness drives the HTTP round-trip, not the
        // browser, so the client-side half is pinned by markup: both drop
        // paths build the drop URL with the adv append, both store the scroll
        // position before submitting, and the reader restores it once.
        $this->adminSession();
        $body = $this->board();
        $this->assertStringContainsString("dropUrl+=(window.location.search?'&':'?')+'adv='+(adv&&adv.classList.contains('show')?'1':'0')", $body, 'The mouse drop appends the advanced panel state.');
        $this->assertStringContainsString("sessionStorage.setItem('tb-kanban-return-scroll',String(Math.round(window.scrollY||0)))", $body, 'The mouse drop stores the scroll position.');
        $this->assertStringContainsString("if(!document.querySelector('#tb-list-region'))return", $body, 'The scroll reader is a kanban-scoped no-op elsewhere.');
        $this->assertStringContainsString("sessionStorage.getItem('tb-kanban-return-scroll')", $body, 'The reader restores the stored position.');
        $this->assertStringContainsString('window.scrollTo(0,saved)', $body, 'The restore scrolls the returned board back.');
        $this->assertSame(2, substr_count($body, "sessionStorage.setItem('tb-kanban-return-scroll'"), 'Both the mouse and the touch drop path store the scroll position.');
        $this->assertSame(2, substr_count($body, "+'adv='+(adv&&adv.classList.contains('show')?'1':'0')"), 'Both drop paths pin the advanced panel state.');
    }

    public function test_an_archived_task_is_refused_by_the_move_action(): void
    {
        $this->adminSession();
        $taskId = $this->adminSession()->createTask('Archived move');
        $this->adminSession()->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId, 'return' => '?']);

        $response = $this->adminSession()->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '2', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('This task is archived and read-only. Restore it from the archive first.', $response->body);
        $this->assertSame([1, 0], $this->ranks()[$taskId], 'The archived task keeps its status and rank.');
    }

    public function test_the_move_action_refuses_an_unknown_status_and_a_malformed_order(): void
    {
        $this->adminSession();
        $taskId = $this->adminSession()->createTask('Bad move');

        $response = $this->adminSession()->post('/?page=kanban', [
            'action' => 'move_kanban_task', 'id' => (string)$taskId, 'status_id' => '999', 'order' => [(string)$taskId],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('alert alert-danger', $response->body);
        $this->assertStringContainsString('Choose a valid status.', $response->body);
        $this->assertSame([1, 0], $this->ranks()[$taskId]);

        // Archived ids in a crafted order list are dropped, not ranked: the
        // move itself still lands for the live task.
        $peer = $this->adminSession()->createTask('Live peer');
        $this->adminSession()->post('/?page=task&id=' . $peer, ['action' => 'archive_task', 'id' => (string)$peer, 'return' => '?']);
        $response = $this->adminSession()->post('/?page=kanban', [
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
        $response = $this->adminSession()->post('/?page=kanban', [
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
