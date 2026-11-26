<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #170: the Quick changes card carries ONE form with ONE save button -
 * the status select and the assignees multi-select post together as the
 * quick_task_changes action, so the two values can be changed in a single
 * save (#153 shipped them as two separate forms that could never be saved
 * together). The combined action keeps the full save's server-side
 * discipline: the same validation, one field-level audit row per changed
 * field and one event row in the same sentence shape a full save composes
 * for several fields, no row at all on an unchanged pick, and archived
 * tasks stay read-only.
 */
final class QuickTaskChangesTest extends E2eTestCase
{
    private function createUser(string $username, string $display, string $password): int
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

    public function test_the_task_view_offers_one_quick_form_with_a_single_save_button(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Quick target', ['assignees' => [(string)$workerId]]);

        $page = $this->session->taskPage($taskId);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('id="quick-changes"', $page->body, 'The Quick changes card is rendered.');
        $this->assertStringContainsString('name="action" value="quick_task_changes"', $page->body, 'The card posts the combined quick action.');
        $this->assertStringContainsString('name="status_id" id="tb-quick-status"', $page->body, 'The status select is present.');
        $this->assertStringContainsString('<option value="1" selected>TODO</option>', $page->body, 'The current status is preselected.');
        $this->assertStringContainsString('<option value="2" >IN PROGRESS</option>', $page->body, 'The other statuses are offered.');
        $this->assertStringContainsString('name="assignees[]" id="tb-quick-assignees" multiple size="4"', $page->body, 'The assignees picker is a real multi-select.');
        $this->assertStringContainsString('<option value="' . $workerId . '" selected>Worker User</option>', $page->body, 'The current assignee is preselected.');
        $this->assertStringContainsString('an empty selection unassigns everybody', $page->body, 'The picker explains the empty selection.');
        $this->assertStringContainsString('Save changes', $page->body, 'One save button serves both pickers.');
        $this->assertStringNotContainsString('quick_task_status', $page->body, 'The #153 status-only form is gone.');
        $this->assertStringNotContainsString('quick_task_assignees', $page->body, 'The #153 assignees-only form is gone.');
        $cardStart = (int)strpos($page->body, 'id="quick-changes"');
        $cardEnd = (int)strpos($page->body, '<h2 class="h5">Attachments</h2>');
        $card = substr($page->body, $cardStart, $cardEnd - $cardStart);
        $this->assertSame(1, substr_count($card, '<form'), 'Exactly one form lives in the card.');
        $this->assertSame(1, substr_count($card, 'type="submit"') + substr_count($card, '<button'), 'Exactly one save button lives in the card.');
    }

    public function test_the_quick_changes_card_sits_right_below_the_comment_form(): void
    {
        // #153 v2: on a phone the right sidebar stacks UNDER the main column,
        // so the pickers buried at the very bottom of the page behind every
        // comment, attachment and history row. The card now lives in the main
        // column immediately after the Comments card - the DOM order pins the
        // placement: comment form -> Quick changes -> Attachments, and the
        // sidebar (Relationships) comes after the card again. #170 keeps the
        // placement byte-identical; only the card's inner markup merges.
        $this->adminSession();
        $taskId = $this->session->createTask('Placement probe');

        $page = $this->session->taskPage($taskId);
        $this->assertSame(200, $page->status);
        $commentFormAt = strpos($page->body, 'name="action" value="comment"');
        $quickChangesAt = strpos($page->body, 'id="quick-changes"');
        $attachmentsAt = strpos($page->body, '<h2 class="h5">Attachments</h2>');
        $relationshipsAt = strpos($page->body, '<h2 class="h5">Relationships</h2>');
        $this->assertNotFalse($commentFormAt, 'The comment form renders.');
        $this->assertNotFalse($quickChangesAt, 'The Quick changes card renders.');
        $this->assertNotFalse($attachmentsAt, 'The Attachments card renders.');
        $this->assertNotFalse($relationshipsAt, 'The Relationships card renders.');
        $this->assertGreaterThan($commentFormAt, $quickChangesAt, 'The card is below the comment form.');
        $this->assertGreaterThan($quickChangesAt, $attachmentsAt, 'The card is right below the comment form, before the Attachments card.');
        $this->assertGreaterThan($quickChangesAt, $relationshipsAt, 'The card is out of the right sidebar: Relationships renders after it.');
        $this->assertStringNotContainsString('card p-4 mt-4" id="quick-changes"', $page->body, 'The card no longer rides the sidebar spacing.');
    }

    public function test_one_save_moves_status_and_roster_together(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $peerId = $this->createUser('peer', 'Peer User', 'PeerPass123456');
        $taskId = $this->session->createTask('One save', ['assignees' => [(string)$workerId]]);

        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '2', 'assignees' => [(string)$peerId, (string)$workerId],
        ]);
        $this->assertSame(302, $response->status, 'The quick save redirects back to the task.');
        $this->assertStringContainsString('page=task&id=' . $taskId, (string)$response->header('location'));

        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Status and assignees updated.', $page->body, 'The confirmation ribbon renders.');
        $this->assertStringContainsString('The followings are changed: Status, Assignees.', $page->body, 'The event summary renders within the 100-char details cut (#80).');
        $eventDetails = (string)TestServer::pdo()->query("SELECT details FROM task_history WHERE task_id=$taskId AND event='Task updated' AND field IS NULL ORDER BY id")->fetchColumn();
        $this->assertSame('The followings are changed: Status, Assignees. Status changed from "TODO" to "IN PROGRESS"; Assignees changed from "Worker User" to "Peer User, Worker User".', $eventDetails, 'The event sentence matches the full save shape for several fields - pinned on the audit row, the page cuts details at 100 chars (#80).');

        $rows = TestServer::pdo()->query("SELECT event, field, old_value, new_value FROM task_history WHERE task_id=$taskId AND id > 1 ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows, 'Exactly one event row and one field row per changed field are written.');
        $this->assertSame('Task updated', $rows[0]['event']);
        $this->assertSame('status', $rows[1]['field']);
        $this->assertSame('TODO', $rows[1]['old_value']);
        $this->assertSame('IN PROGRESS', $rows[1]['new_value']);
        $this->assertSame('assignees', $rows[2]['field']);
        $this->assertSame('Worker User', $rows[2]['old_value']);
        $this->assertSame('Peer User, Worker User', $rows[2]['new_value'], 'The new roster is recorded in pick order.');
        $this->assertSame('IN PROGRESS', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn(), 'The status really moved.');
        $this->assertSame('2', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn(), 'Both assignees are stored.');

        $notifications = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM notifications WHERE user_id=$peerId AND task_id=$taskId")->fetchColumn();
        $this->assertGreaterThan(0, $notifications, 'The fresh assignee is notified (#58), like a full save would.');
    }

    public function test_a_status_only_quick_save_keeps_the_single_field_sentence(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Status only', ['assignees' => [(string)$workerId]]);

        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '2', 'assignees' => [(string)$workerId],
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Status updated.', $page->body, 'The confirmation names the field that moved.');
        $this->assertStringContainsString('The followings are changed: Status. Status changed from &quot;TODO&quot; to &quot;IN PROGRESS&quot;.', $page->body, 'The single-field event sentence is byte-identical to the pre-#170 quick move.');
        $this->assertStringContainsString('Field &quot;status&quot;: from &quot;TODO&quot; to &quot;IN PROGRESS&quot;', $page->body, 'The field-level audit row renders.');

        $rows = TestServer::pdo()->query("SELECT event, field FROM task_history WHERE task_id=$taskId AND id > 1 ORDER BY id")->fetchAll();
        $this->assertCount(2, $rows, 'Exactly the event row and the status field row are written.');
        $this->assertSame('status', $rows[1]['field']);
        $this->assertSame('1', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId AND user_id=$workerId")->fetchColumn(), 'An unchanged roster is not rewritten.');
    }

    public function test_an_assignees_only_quick_save_rewrites_the_roster(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $peerId = $this->createUser('peer', 'Peer User', 'PeerPass123456');
        $taskId = $this->session->createTask('Roster rewrite', ['assignees' => [(string)$workerId]]);

        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '1', 'assignees' => [(string)$peerId, (string)$workerId],
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Assignees updated.', $page->body);
        $this->assertSame('TODO', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn(), 'An unchanged status is not rewritten.');

        $rows = TestServer::pdo()->query("SELECT field, old_value, new_value FROM task_history WHERE task_id=$taskId AND field IS NOT NULL ORDER BY id")->fetchAll();
        $this->assertCount(1, $rows, 'One field-level audit row records the roster change.');
        $this->assertSame('assignees', $rows[0]['field']);
        $this->assertSame('Worker User', $rows[0]['old_value']);
        $this->assertSame('Peer User, Worker User', $rows[0]['new_value'], 'The new roster is recorded in pick order.');

        // Unassigning everybody: an empty multi-select submission (no key at
        // all) leaves the task unassigned and the audit says so.
        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId, 'status_id' => '1',
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Unassigned', $page->body);
        $this->assertSame('0', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn());
        $rows = TestServer::pdo()->query("SELECT old_value, new_value FROM task_history WHERE task_id=$taskId AND field='assignees' ORDER BY id")->fetchAll();
        $this->assertSame('Peer User, Worker User', $rows[1]['old_value']);
        $this->assertSame('—', $rows[1]['new_value'], 'The empty roster is recorded as the em dash, like a full save writes it.');
    }

    public function test_the_assignees_only_quick_save_notifies_the_new_assignee(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Notify the pick');

        $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '1', 'assignees' => [(string)$workerId],
        ]);

        $notifications = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM notifications WHERE user_id=$workerId AND task_id=$taskId")->fetchColumn();
        $this->assertGreaterThan(0, $notifications, 'The fresh assignee is notified (#58), like a full save would.');
    }

    public function test_an_unchanged_quick_save_writes_no_history_row(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Steady pick', ['assignees' => [(string)$workerId]]);

        $before = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '1', 'assignees' => [(string)$workerId],
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Nothing changed.', $page->body, 'The no-op says so honestly.');
        $after = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $this->assertSame($before, $after, 'A no-op writes no row.');
    }

    public function test_the_quick_form_refuses_an_unknown_status(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Bad status', ['assignees' => [(string)$workerId]]);

        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '999', 'assignees' => [(string)$workerId],
        ]);
        $this->assertSame(200, $response->status, 'The failure re-renders the task page with the error.');
        $this->assertStringContainsString('alert alert-danger', $response->body);
        $this->assertStringContainsString('Choose a valid status.', $response->body);
        $this->assertSame('TODO', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn(), 'The status is untouched.');
        $this->assertSame('1', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn(), 'The roster is untouched.');
    }

    public function test_the_quick_form_refuses_invalid_assignees(): void
    {
        $this->adminSession();
        $taskId = $this->session->createTask('Bad roster');

        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '2', 'assignees' => ['999'],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('alert alert-danger', $response->body);
        $this->assertStringContainsString('Choose valid assignees.', $response->body);
        $this->assertSame('TODO', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn(), 'The status is untouched when the roster fails validation.');
        $this->assertSame('0', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn(), 'The roster is untouched.');
    }

    public function test_archived_tasks_stay_read_only_for_the_quick_form(): void
    {
        $admin = $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $admin->createTask('Archived quick', ['assignees' => [(string)$workerId]]);

        $admin->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId, 'return' => '?']);
        $page = $admin->taskPage($taskId);
        $this->assertStringNotContainsString('id="quick-changes"', $page->body, 'The card is not rendered on an archived task.');
        $this->assertStringNotContainsString('name="status_id" id="tb-quick-status"', $page->body);

        $response = $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '2', 'assignees' => ['1'],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('This task is archived and read-only. Restore it from the archive first.', $response->body, 'The server-side guard refuses the save.');
        $this->assertSame('TODO', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn());
        $this->assertSame('1', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn());
    }

    public function test_a_plain_user_can_use_the_quick_form_like_the_edit_form(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Plain powers');

        $worker = new AppSession('worker', 'WorkerPass12345');
        $page = $worker->taskPage($taskId);
        $this->assertStringContainsString('id="quick-changes"', $page->body, 'The quick powers mirror the edit form: every signed-in user gets them.');

        $response = $worker->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_changes', 'id' => (string)$taskId,
            'status_id' => '2', 'assignees' => [(string)$workerId],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('IN PROGRESS', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn());
        $this->assertSame('1', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId AND user_id=$workerId")->fetchColumn());
    }

    public function test_the_quick_form_refuses_an_unknown_task(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=task&id=9999', [
            'action' => 'quick_task_changes', 'id' => '9999',
            'status_id' => '2', 'assignees' => ['1'],
        ]);
        $this->assertSame(404, $response->status, 'The re-rendered task page 404s for a missing task.');
        $this->assertStringContainsString('Task not found.', $response->body);
    }
}
