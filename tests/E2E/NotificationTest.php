<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #58: notifications - the board drops one notification into every
 * assignee's stream when a task-history event hits their task (minus the
 * actor's own edits), a red navbar badge counts the unseen ones and viewing
 * the stream marks them seen. Notifications cascade away with their task or
 * their recipient, and the schema self-heals on existing boards like every
 * other feature (#24/#53 discipline).
 */
final class NotificationTest extends E2eTestCase
{
    private function createRegularUser(AppSession $admin, string $username): AppSession
    {
        $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => ucfirst($username),
            'password' => $username . 'Pass12345',
            'role' => 'user',
        ]);
        return new AppSession($username, $username . 'Pass12345');
    }

    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'User ' . $username . ' does not exist.');
        return (int)$id;
    }

    private function unseenCount(int $userId): int
    {
        return (int)TestServer::pdo()->query('SELECT COUNT(*) FROM notifications WHERE user_id=' . $userId . ' AND is_seen=0')->fetchColumn();
    }

    public function test_a_comment_by_another_user_notifies_the_assignee(): void
    {
        $admin = $this->adminSession();
        $wanda = $this->createRegularUser($admin, 'wanda');
        $id = $admin->createTask('Watched task', ['assignees' => [$this->userId('wanda')]]);

        $this->assertSame(302, $admin->post('/?page=task&id=' . $id, [
            'action' => 'comment',
            'task_id' => (string)$id,
            'body' => 'Heads up, Wanda',
        ])->status);

        // The assignee sees the red navbar badge with the count...
        $badge = $wanda->get('/?');
        // Creation with an assignee is already an event (#58), the comment adds
        // the second one - the badge counts both unseen notifications.
        $this->assertTrue($badge->contains('<a class="nav-link" href="?page=notifications">Notifications <span class="badge text-bg-danger" title="New notifications">2</span></a>'));
        // ...and the stream shows the event with a "new" marker, linking the task.
        $stream = $wanda->get('/?page=notifications');
        $this->assertTrue($stream->contains('<a class="fw-semibold text-decoration-none" href="?page=task&id=' . $id . '">#' . $id . ' · Watched task</a>'));
        $this->assertTrue($stream->contains('Comment added'));
        $this->assertTrue($stream->contains('<span class="badge text-bg-danger">new</span>'));
        // Viewing the stream marks everything seen: the badge is gone afterwards.
        $this->assertSame(0, $this->unseenCount($this->userId('wanda')));
        $this->assertFalse($wanda->get('/?')->contains('title="New notifications"'));
    }

    public function test_the_actor_does_not_notify_themselves(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Solo task', ['assignees' => [$this->userId('admin')]]);

        $this->assertSame(302, $admin->post('/?page=task&id=' . $id, [
            'action' => 'comment',
            'task_id' => (string)$id,
            'body' => 'Note to self',
        ])->status);

        $this->assertSame(0, $this->unseenCount($this->userId('admin')), 'Your own edits must not ping you.');
        $page = $admin->get('/?page=notifications');
        $this->assertTrue($page->contains('No notifications yet.'));
    }

    public function test_a_task_save_notifies_once_not_once_per_field(): void
    {
        $admin = $this->adminSession();
        $this->createRegularUser($admin, 'walter');
        $id = $admin->createTask('Edited task', ['assignees' => [$this->userId('walter')]]);

        // One save writes one event-level row plus one audit row per field (#54).
        // The creation itself already notified the assignee (see #58), so the
        // assertion measures the DELTA this save adds: exactly one.
        $unseenBeforeSave = $this->unseenCount($this->userId('walter'));
        $this->assertSame(302, $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Edited task, retitled',
            'description' => 'New description',
            'status_id' => '1',
            'parent_id' => '',
            'assignees' => [(string)$this->userId('walter')],
        ])->status);

        $this->assertSame($unseenBeforeSave + 1, $this->unseenCount($this->userId('walter')), 'One save = exactly one notification.');
        $page = (new AppSession('walter', 'walterPass12345'))->get('/?page=notifications');
        $this->assertTrue($page->contains('Task updated'));
        $this->assertSame(1, substr_count($page->body, 'Task updated'), 'Field-level audit rows stay notification-free.');
    }

    public function test_deleting_the_task_removes_its_notifications(): void
    {
        $admin = $this->adminSession();
        $this->createRegularUser($admin, 'vera');
        $id = $admin->createTask('Doomed task', ['assignees' => [$this->userId('vera')]]);
        $admin->post('/?page=task&id=' . $id, ['action' => 'comment', 'task_id' => (string)$id, 'body' => 'notify me']);
        $this->assertSame(2, $this->unseenCount($this->userId('vera')), 'Task created and Comment added both notify.');

        // Deleting goes through the archive (#12), then removes permanently.
        $admin->post('/?page=task&id=' . $id, ['action' => 'archive_task', 'id' => (string)$id]);
        $admin->post('/?page=task&id=' . $id, ['action' => 'delete_task', 'id' => (string)$id, 'return' => '?page=archive']);

        $this->assertSame(0, $this->unseenCount($this->userId('vera')), 'The FK cascade clears the gone task\'s notifications.');
    }

    public function test_anonymous_users_are_pushed_to_sign_in(): void
    {
        $response = $this->session->get('/?page=notifications');
        $this->assertSame(302, $response->status, 'Guarded pages redirect anonymous visitors.');
        $this->assertStringContainsString('page=login', (string)$response->header('location'));
    }
}
