<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #156: the statuses admin page orders its rows by drag & drop - a grip
 * handle is the native HTML5 draggable, a drop reorders the rows and POSTs
 * their new id order through a plain form, and the reorder_statuses action
 * renumbers sort_order 1..N in exactly that order (the same display order
 * the board columns, the dropdowns and the edit forms always read). Only
 * known status ids take a position, every stored status keeps one (a short
 * list appends the missing ones in their previous order), and the action is
 * administrator-only like the rest of the page.
 */
final class StatusDragDropTest extends E2eTestCase
{
    private function orders(): array
    {
        $rows = TestServer::pdo()->query('SELECT id, sort_order FROM statuses ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $row) $out[(int)$row['id']] = (int)$row['sort_order'];
        return $out;
    }

    public function test_the_statuses_page_renders_draggable_rows_with_grip_handles(): void
    {
        $admin = $this->adminSession();

        $page = $admin->get('/?page=statuses');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('data-tb-status-list', $page->body, 'The list is the drop zone.');
        $this->assertStringContainsString('data-tb-status-row data-status-id="1"', $page->body, 'The rows carry their status id.');
        $this->assertStringContainsString('draggable="true" data-tb-status-handle', $page->body, 'The grip handle is the draggable.');
        $this->assertStringContainsString("field('action','reorder_statuses')", $page->body, 'The drop script posts the reorder action.');
        $this->assertStringContainsString('.tb-status-dragging{opacity:.45}', $page->body, 'The dragging visual is defined.');
        // The pre-#156 row mechanics stay: the per-row save form and the
        // numeric order field keep working beside the gesture.
        $this->assertStringContainsString('action" value="edit_status"', $page->body);
        $this->assertStringContainsString('name="sort_order"', $page->body);
    }

    public function test_the_statuses_page_ships_the_touch_drag_path_for_phones_and_tablets(): void
    {
        // #156 v2: the native HTML5 drag never fires on touch devices, so the
        // grip did nothing on mobile. The page ships a pointer/touch path
        // beside it - press and hold on the grip lifts the row, the row under
        // the finger lights up and the same reorder_statuses form is submitted
        // on lift. Only the grip starts a touch drag and the mouse keeps the
        // HTML5 path, so the row's inputs and desktop behaviour are unchanged.
        $admin = $this->adminSession();

        $page = $admin->get('/?page=statuses');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString("e.pointerType==='mouse')return", $page->body, 'The mouse keeps the native HTML5 drag path.');
        $this->assertStringContainsString('PICK_DELAY', $page->body, 'The press-and-hold pick-up delay is defined.');
        $this->assertStringContainsString('[data-tb-status-handle]', $page->body, 'Only the grip starts a touch drag.');
        $this->assertStringContainsString("field('action','reorder_statuses')", $page->body, 'The touch drop submits the same reorder_statuses action.');
        $this->assertStringContainsString('.tb-status-over{outline:2px dashed', $page->body, 'The row highlight visual is defined.');
        $this->assertStringContainsString('.tb-status-noselect{-webkit-touch-callout:none', $page->body, 'The pick-up suppresses the long-press callout.');
        $this->assertStringContainsString('{passive:false}', $page->body, 'The touchmove scroll blocker is registered non-passive.');
        $this->assertStringContainsString('press and hold the grip', $page->body, 'The hint explains the touch gesture.');
    }

    public function test_a_drop_renumbers_the_statuses_in_the_submitted_order(): void
    {
        $admin = $this->adminSession();

        // Fresh install: TODO(1), IN PROGRESS(2), DONE(3). Drop DONE on top.
        $response = $admin->post('/?page=statuses', [
            'action' => 'reorder_statuses', 'order' => ['3', '1', '2'],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('page=statuses', (string)$response->header('location'));

        $orders = $this->orders();
        $this->assertSame(1, $orders[3], 'The first submitted row takes position 1.');
        $this->assertSame(2, $orders[1], 'The second takes position 2.');
        $this->assertSame(3, $orders[2], 'The third takes position 3.');

        $page = $admin->get('/?page=statuses');
        $this->assertStringContainsString('Status order updated.', $page->body, 'The confirmation ribbon renders.');
        $donePos = strpos($page->body, 'data-status-id="3"');
        $todoPos = strpos($page->body, 'data-status-id="1"');
        $progressPos = strpos($page->body, 'data-status-id="2"');
        $this->assertTrue($donePos < $todoPos && $todoPos < $progressPos, 'The rows render in the dropped order.');

        // The board columns follow the same display order they always read.
        $kanban = $admin->get('/?page=kanban');
        $this->assertTrue(
            strpos($kanban->body, 'DONE') < strpos($kanban->body, 'TODO'),
            'The kanban columns follow the renumbered display order.'
        );
    }

    public function test_a_crafted_order_drops_unknown_ids_and_appends_the_missing_ones(): void
    {
        $admin = $this->adminSession();

        // Unknown ids cannot take a position; statuses missing from the list
        // follow appended in their previous order - every row keeps one.
        $response = $admin->post('/?page=statuses', [
            'action' => 'reorder_statuses', 'order' => ['999', '2'],
        ]);
        $this->assertSame(302, $response->status);
        $orders = $this->orders();
        $this->assertSame(1, $orders[2], 'The submitted known row leads.');
        $this->assertSame(2, $orders[1], 'The first missing row follows in its previous order.');
        $this->assertSame(3, $orders[3], 'The second missing row keeps the tail.');

        // An empty order list is refused honestly.
        $response = $admin->post('/?page=statuses', ['action' => 'reorder_statuses']);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Nothing to order.', $response->body);
        $this->assertSame(1, $this->orders()[2], 'The refusal changed nothing.');
    }

    public function test_only_an_administrator_can_reorder(): void
    {
        $this->adminSession();
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user', 'username' => 'worker',
            'display_name' => 'Worker User', 'password' => 'WorkerPass12345', 'role' => 'user',
        ]);
        $worker = new AppSession('worker', 'WorkerPass12345');

        $response = $worker->post('/?page=statuses', [
            'action' => 'reorder_statuses', 'order' => ['3', '2', '1'],
        ]);
        $this->assertSame(403, $response->status, 'The statuses page answers 403 to everyone but an administrator.');
        $this->assertStringContainsString('Forbidden.', $response->body);
        $this->assertSame([1, 2, 3], array_values($this->orders()), 'The stored order is untouched by the plain user.');
    }
}
