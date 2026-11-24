<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #58: notifications - the board drops one notification into every
 * assignee's stream when a task-history event hits their task (minus the
 * actor's own edits) and a red navbar badge counts the unseen ones.
 * Task #73: marking seen is per item - opening the stream alone clears
 * nothing; clicking a notification marks exactly that one seen and lands on
 * its task, foreign or bogus ids bounce back without marking anything.
 * Notifications cascade away with their task or their recipient, and the
 * schema self-heals on existing boards like every other feature (#24/#53
 * discipline).
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

    /** The user's notification stream rows, newest first (id included). */
    private function notifications(string $username): array
    {
        $s = TestServer::pdo()->prepare(
            'SELECT n.*, t.title task_title FROM notifications n '
            . 'JOIN tasks t ON t.id=n.task_id JOIN users u ON u.id=n.user_id '
            . 'WHERE u.username=? ORDER BY n.id DESC'
        );
        $s->execute([$username]);
        return $s->fetchAll();
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
        $this->assertTrue($badge->contains('<a class="nav-link" href="?page=notifications-v2">Notifications <span class="badge text-bg-danger" title="New notifications">2</span></a>'));  // #85: the menu entry opens the canonical v2 stream.
        // ...and the stream shows the event with a "new" marker. Every row
        // links through the per-item seen route (#73), carrying its own id.
        $stream = $wanda->get('/?page=notifications');
        $rows = $this->notifications('wanda');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($stream->contains('<a class="fw-semibold text-decoration-none" href="?page=notification_seen&id=' . $row['id'] . '">#' . $id . ' · Watched task</a>'));
        }
        $this->assertTrue($stream->contains('Comment added'));
        // The rows still render their "new" markers on the consuming load
        // (the list is read before the mark-seen), but the flag itself is
        // gone on this very page (#79 point 2)...
        $this->assertSame(2, substr_count($stream->body, '<span class="badge text-bg-danger">new</span>'));
        $this->assertFalse($stream->contains('badge text-bg-danger" title="New notifications"'));
        // ...and everywhere else from now on, until new unseen ones arrive.
        $this->assertSame(0, $this->unseenCount($this->userId('wanda')));
        $this->assertFalse($wanda->get('/?')->contains('title="New notifications"'));
    }

    public function test_clicking_a_notification_marks_only_it_seen_and_opens_the_task(): void
    {
        $admin = $this->adminSession();
        $wendy = $this->createRegularUser($admin, 'wendy');
        $id = $admin->createTask('Clicked task', ['assignees' => [$this->userId('wendy')]]);
        $admin->post('/?page=task&id=' . $id, ['action' => 'comment', 'task_id' => (string)$id, 'body' => 'second event']);
        $this->assertSame(2, $this->unseenCount($this->userId('wendy')));

        // Clicking the newest notification marks exactly that one seen and
        // lands on the task it came from.
        $rows = $this->notifications('wendy');
        $response = $wendy->get('/?page=notification_seen&id=' . $rows[0]['id']);
        $this->assertSame(302, $response->status, 'The click-through redirects to the notification target.');
        $this->assertSame('?page=task&id=' . $id, (string)$response->header('location'));
        $this->assertSame(1, $this->unseenCount($this->userId('wendy')), 'Only the clicked notification becomes seen.');

        // The navbar badge counts exactly the remainder on pages that do not
        // consume the stream.
        $this->assertTrue($wendy->get('/?')->contains('title="New notifications">1</span>'));

        // Clicking the last unseen one clears the badge completely...
        $response = $wendy->get('/?page=notification_seen&id=' . $rows[1]['id']);
        $this->assertSame(302, $response->status);
        $this->assertSame(0, $this->unseenCount($this->userId('wendy')));
        $this->assertFalse($wendy->get('/?')->contains('title="New notifications"'));
        // ...and re-clicking a seen notification is a safe no-op that still
        // lands on the task.
        $response = $wendy->get('/?page=notification_seen&id=' . $rows[1]['id']);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=task&id=' . $id, (string)$response->header('location'));
        $this->assertSame(0, $this->unseenCount($this->userId('wendy')));
    }

    public function test_a_notification_cannot_be_seen_by_another_user_or_with_a_bogus_id(): void
    {
        $admin = $this->adminSession();
        $wilma = $this->createRegularUser($admin, 'wilma');
        $admin->createTask('Owned task', ['assignees' => [$this->userId('wilma')]]);
        $rows = $this->notifications('wilma');
        $this->assertCount(1, $rows);

        // Another account gets bounced back to the stream, the row stays unseen.
        $response = $admin->get('/?page=notification_seen&id=' . $rows[0]['id']);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=notifications-v2', (string)$response->header('location'), 'The bounce lands on the canonical stream (#85).');
        $this->assertSame(1, $this->unseenCount($this->userId('wilma')), 'A foreign id must not mark anything seen.');

        // A nonexistent (or already cascaded-away) id behaves the same.
        $response = $wilma->get('/?page=notification_seen&id=99999999');
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=notifications-v2', (string)$response->header('location'), 'The bounce lands on the canonical stream (#85).');
        $this->assertSame(1, $this->unseenCount($this->userId('wilma')));
    }

    public function test_the_mark_all_button_clears_the_whole_stream(): void
    {
        $admin = $this->adminSession();
        $nora = $this->createRegularUser($admin, 'nora');
        $id = $admin->createTask('Bulk task', ['assignees' => [$this->userId('nora')]]);
        $admin->post('/?page=task&id=' . $id, ['action' => 'comment', 'task_id' => (string)$id, 'body' => 'bulk ping']);
        $this->assertSame(2, $this->unseenCount($this->userId('nora')));

        // The button sits next to the counter and only while something is new.
        $stream = $nora->get('/?page=notifications');
        $this->assertTrue($stream->contains('name="action" value="mark_all_seen"'));

        // One POST clears everything: the counter, the navbar flag and the
        // per-row "new" markers all go away (#79)...
        $response = $nora->post('/?page=notifications', ['action' => 'mark_all_seen']);
        $this->assertSame(302, $response->status);
        $this->assertSame(0, $this->unseenCount($this->userId('nora')));
        // ...and stays cleared - the flag only comes back with new unseen ones.
        $stream = $nora->get('/?page=notifications');
        $this->assertSame(0, substr_count($stream->body, '<span class="badge text-bg-danger">new</span>'));
        $this->assertFalse($nora->get('/?')->contains('title="New notifications"'));
        $this->assertStringNotContainsString('mark_all_seen', $stream->body, 'Nothing new, so the button hides again.');
    }

    public function test_opening_the_stream_clears_the_flag_until_new_ones_arrive(): void
    {
        $admin = $this->adminSession();
        $petra = $this->createRegularUser($admin, 'petra');
        $id = $admin->createTask('Flag task', ['assignees' => [$this->userId('petra')]]);

        // Opening the stream consumes everything: the navbar flag disappears
        // on the very page that did it (#79 point 2)...
        $stream = $petra->get('/?page=notifications');
        $this->assertSame(0, $this->unseenCount($this->userId('petra')));
        $this->assertFalse($stream->contains('badge text-bg-danger" title="New notifications"'), 'The flag is gone on the page that consumed the notifications.');
        $this->assertFalse($petra->get('/?')->contains('title="New notifications"'));

        // ...and comes back the moment something new arrives.
        $admin->post('/?page=task&id=' . $id, ['action' => 'comment', 'task_id' => (string)$id, 'body' => 'fresh ping']);
        $this->assertTrue($petra->get('/?')->contains('title="New notifications">1</span>'));
    }

    public function test_the_mark_all_button_is_hidden_when_nothing_is_new(): void
    {
        $admin = $this->adminSession();
        $oleg = $this->createRegularUser($admin, 'oleg');
        $page = $oleg->get('/?page=notifications');
        $this->assertTrue($page->contains('No notifications yet.'));
        $this->assertStringNotContainsString('mark_all_seen', $page->body, 'The bulk button has nothing to clear.');
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

        // The per-item seen route (#73) is guarded the same way.
        $response = $this->session->get('/?page=notification_seen&id=1');
        $this->assertSame(302, $response->status, 'Guarded pages redirect anonymous visitors.');
        $this->assertStringContainsString('page=login', (string)$response->header('location'));
    }
}
