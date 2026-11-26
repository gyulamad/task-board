<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #153: the task view page carries a Quick changes card - a status
 * select and the assignees multi-select - so a ticket can be moved around on
 * the kanban board without opening the full edit form. Both quick actions
 * mirror the full save's server-side discipline: the same validation, the
 * same field-level audit rows and the same event sentence, no row at all on
 * an unchanged pick, and archived tasks stay read-only.
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

    public function test_the_task_view_offers_the_quick_status_select_and_the_assignees_multi_select(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Quick target', ['assignees' => [(string)$workerId]]);

        $page = $this->session->taskPage($taskId);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('id="quick-changes"', $page->body, 'The Quick changes card is rendered.');
        $this->assertStringContainsString('name="action" value="quick_task_status"', $page->body, 'The status form posts the quick action.');
        $this->assertStringContainsString('name="status_id" id="tb-quick-status"', $page->body, 'The status select is present.');
        $this->assertStringContainsString('<option value="1" selected>TODO</option>', $page->body, 'The current status is preselected.');
        $this->assertStringContainsString('<option value="2" >IN PROGRESS</option>', $page->body, 'The other statuses are offered.');
        $this->assertStringContainsString('name="action" value="quick_task_assignees"', $page->body, 'The assignees form posts the quick action.');
        $this->assertStringContainsString('name="assignees[]" id="tb-quick-assignees" multiple size="4"', $page->body, 'The assignees picker is a real multi-select.');
        $this->assertStringContainsString('<option value="' . $workerId . '" selected>Worker User</option>', $page->body, 'The current assignee is preselected.');
        $this->assertStringContainsString('an empty selection unassigns everybody', $page->body, 'The picker explains the empty selection.');
    }

    public function test_the_quick_changes_card_sits_right_below_the_comment_form(): void
    {
        // #153 v2: on a phone the right sidebar stacks UNDER the main column,
        // so the pickers buried at the very bottom of the page behind every
        // comment, attachment and history row. The card now lives in the main
        // column immediately after the Comments card - the DOM order pins the
        // placement: comment form -> Quick changes -> Attachments, and the
        // sidebar (Relationships) comes after the card again.
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

    public function test_the_quick_status_select_moves_the_task_with_the_full_audit_trail(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Quick move');

        $response = $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_status', 'id' => (string)$taskId, 'status_id' => '2',
        ]);
        $this->assertSame(302, $response->status, 'The quick save redirects back to the task.');
        $this->assertStringContainsString('page=task&id=' . $taskId, (string)$response->header('location'));

        $page = $admin->taskPage($taskId);
        $this->assertStringContainsString('Status updated.', $page->body, 'The confirmation ribbon renders.');
        $this->assertStringContainsString('The followings are changed: Status. Status changed from &quot;TODO&quot; to &quot;IN PROGRESS&quot;.', $page->body, 'The event sentence matches the full save shape.');
        $this->assertStringContainsString('Field &quot;status&quot;: from &quot;TODO&quot; to &quot;IN PROGRESS&quot;', $page->body, 'The field-level audit row renders.');

        $rows = TestServer::pdo()->query("SELECT event, field, old_value, new_value FROM task_history WHERE task_id=$taskId AND id > 1 ORDER BY id")->fetchAll();
        $this->assertCount(2, $rows, 'Exactly the event row and the field row are written.');
        $this->assertSame('Task updated', $rows[0]['event']);
        $this->assertSame('status', $rows[1]['field']);
        $this->assertSame('TODO', $rows[1]['old_value']);
        $this->assertSame('IN PROGRESS', $rows[1]['new_value']);
        $this->assertSame('IN PROGRESS', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn(), 'The status really moved.');
    }

    public function test_an_unchanged_quick_status_writes_no_history_row(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Unchanged pick');

        $before = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $response = $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_status', 'id' => (string)$taskId, 'status_id' => '1',
        ]);
        $this->assertSame(302, $response->status);
        $page = $admin->taskPage($taskId);
        $this->assertStringContainsString('Status unchanged.', $page->body, 'The no-op says so honestly.');
        $after = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $this->assertSame($before, $after, 'A no-op writes no row.');
    }

    public function test_the_quick_status_select_refuses_an_unknown_status(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Bad status');

        $response = $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_status', 'id' => (string)$taskId, 'status_id' => '999',
        ]);
        $this->assertSame(200, $response->status, 'The failure re-renders the task page with the error.');
        $this->assertStringContainsString('alert alert-danger', $response->body);
        $this->assertStringContainsString('Choose a valid status.', $response->body);
        $this->assertSame('TODO', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn(), 'The status is untouched.');
    }

    public function test_the_quick_assignees_multi_select_rewrites_the_roster(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $peerId = $this->createUser('peer', 'Peer User', 'PeerPass123456');
        $taskId = $this->session->createTask('Roster rewrite', ['assignees' => [(string)$workerId]]);

        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_assignees', 'id' => (string)$taskId,
            'assignees' => [(string)$peerId, (string)$workerId],
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Assignees updated.', $page->body);
        $this->assertStringContainsString('Worker User', $page->body);
        $this->assertStringContainsString('Peer User', $page->body);
        $this->assertSame('2', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn(), 'Both assignees are stored.');

        $rows = TestServer::pdo()->query("SELECT field, old_value, new_value FROM task_history WHERE task_id=$taskId AND field IS NOT NULL ORDER BY id")->fetchAll();
        $this->assertCount(1, $rows, 'One field-level audit row records the roster change.');
        $this->assertSame('assignees', $rows[0]['field']);
        $this->assertSame('Worker User', $rows[0]['old_value']);
        $this->assertSame('Peer User, Worker User', $rows[0]['new_value'], 'The new roster is recorded in pick order.');

        // Unassigning everybody: an empty multi-select submission (no key at
        // all) leaves the task unassigned and the audit says so.
        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_assignees', 'id' => (string)$taskId,
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Unassigned', $page->body);
        $this->assertSame('0', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn());
        $rows = TestServer::pdo()->query("SELECT old_value, new_value FROM task_history WHERE task_id=$taskId AND field='assignees' ORDER BY id")->fetchAll();
        $this->assertSame('Peer User, Worker User', $rows[1]['old_value']);
        $this->assertSame('—', $rows[1]['new_value'], 'The empty roster is recorded as the em dash, like a full save writes it.');
    }

    public function test_the_quick_assignees_change_notifies_the_new_assignee(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Notify the pick');

        $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_assignees', 'id' => (string)$taskId, 'assignees' => [(string)$workerId],
        ]);

        $notifications = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM notifications WHERE user_id=$workerId AND task_id=$taskId")->fetchColumn();
        $this->assertGreaterThan(0, $notifications, 'The fresh assignee is notified (#58), like a full save would.');
    }

    public function test_an_unchanged_quick_roster_writes_no_history_row(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Steady roster', ['assignees' => [(string)$workerId]]);

        $before = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $response = $this->session->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_assignees', 'id' => (string)$taskId, 'assignees' => [(string)$workerId],
        ]);
        $this->assertSame(302, $response->status);
        $page = $this->session->taskPage($taskId);
        $this->assertStringContainsString('Assignees unchanged.', $page->body, 'The no-op says so honestly.');
        $after = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id=$taskId")->fetchColumn();
        $this->assertSame($before, $after, 'A no-op writes no row.');
    }

    public function test_archived_tasks_stay_read_only_for_the_quick_actions(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Archived quick');

        $admin->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId, 'return' => '?']);
        $page = $admin->taskPage($taskId);
        $this->assertStringNotContainsString('id="quick-changes"', $page->body, 'The card is not rendered on an archived task.');
        $this->assertStringNotContainsString('name="status_id" id="tb-quick-status"', $page->body);

        $response = $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_status', 'id' => (string)$taskId, 'status_id' => '2',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('This task is archived and read-only. Restore it from the archive first.', $response->body, 'The server-side guard refuses the move.');

        $response = $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_assignees', 'id' => (string)$taskId, 'assignees' => ['1'],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('This task is archived and read-only. Restore it from the archive first.', $response->body);
        $this->assertSame('TODO', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn());
        $this->assertSame('0', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId")->fetchColumn());
    }

    public function test_a_plain_user_can_use_the_quick_changes_like_the_edit_form(): void
    {
        $this->adminSession();
        $workerId = $this->createUser('worker', 'Worker User', 'WorkerPass12345');
        $taskId = $this->session->createTask('Plain powers');

        $worker = new AppSession('worker', 'WorkerPass12345');
        $page = $worker->taskPage($taskId);
        $this->assertStringContainsString('id="quick-changes"', $page->body, 'The quick powers mirror the edit form: every signed-in user gets them.');

        $response = $worker->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_status', 'id' => (string)$taskId, 'status_id' => '2',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('IN PROGRESS', (string)TestServer::pdo()->query("SELECT s.name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.id=$taskId")->fetchColumn());

        $response = $worker->post('/?page=task&id=' . $taskId, [
            'action' => 'quick_task_assignees', 'id' => (string)$taskId, 'assignees' => [(string)$workerId],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('1', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM task_assignees WHERE task_id=$taskId AND user_id=$workerId")->fetchColumn());
    }

    public function test_the_quick_actions_refuse_an_unknown_task(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=task&id=9999', [
            'action' => 'quick_task_status', 'id' => '9999', 'status_id' => '2',
        ]);
        $this->assertSame(404, $response->status, 'The re-rendered task page 404s for a missing task.');
        $this->assertStringContainsString('Task not found.', $response->body);

        $response = $admin->post('/?page=task&id=9999', [
            'action' => 'quick_task_assignees', 'id' => '9999', 'assignees' => ['1'],
        ]);
        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('Task not found.', $response->body);
    }
}
