<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

/**
 * Task #110: the relative "how long ago" rendering. Every list and detail
 * page that shows a datetime opts into tb_ago() - the absolute datetime in
 * the viewer's zone/format with the (15 minutes ago)-style note appended.
 * A stamp that was never set stays the bare 'never' marker with no suffix.
 */
final class RelativeTimeRenderingTest extends E2eTestCase
{
    private function stampOf(string $sql): int
    {
        return (int)TestServer::pdo()->query($sql)->fetchColumn();
    }

    public function test_the_task_detail_page_shows_how_long_ago(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Aged task');
        // Age the task 15 minutes: the bucket is wide enough that the few
        // milliseconds of request handling cannot flip it.
        TestServer::pdo()->exec('UPDATE tasks SET created_at=' . (time() - 900) . ', updated_at=' . (time() - 900) . ' WHERE id=' . $id);

        $page = $admin->taskPage($id);
        $stamp = $this->stampOf('SELECT created_at FROM tasks WHERE id=' . $id);
        $this->assertSame(1, preg_match('/\((\d+) minutes ago\)/', $page->body, $m), 'The task page carries a minutes-ago note.');
        $this->assertSame(intdiv(time() - $stamp, 60), (int)$m[1], 'The note counts from the stored stamp, not from a page cache.');
        $this->assertTrue($page->contains('Created by'), 'The task page rendered.');
    }

    public function test_the_days_bucket_renders_on_the_task_page(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Two days old task');
        TestServer::pdo()->exec('UPDATE tasks SET created_at=' . (time() - 2 * 86400) . ', updated_at=' . (time() - 2 * 86400) . ' WHERE id=' . $id);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('(2 days ago)'), 'The task page renders the days bucket.');
    }

    public function test_the_task_list_updated_cell_shows_how_long_ago(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('List aged task');
        TestServer::pdo()->exec('UPDATE tasks SET updated_at=' . (time() - 900) . ' WHERE id=' . $id);

        $list = $admin->get('/?page=tasks-v2');
        $this->assertSame(200, $list->status);
        $this->assertTrue($list->contains(' (15 minutes ago)'), 'The list Updated cell renders the relative note.');
    }

    public function test_comments_and_history_rows_show_how_long_ago(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Commented task');
        $admin->post('/?page=task&id=' . $id, ['action' => 'comment', 'task_id' => (string)$id, 'body' => 'Aged comment body']);

        // Age the comment row and the history rows it produced (30 minutes).
        TestServer::pdo()->exec('UPDATE comments SET created_at=' . (time() - 1800) . ' WHERE task_id=' . $id);
        TestServer::pdo()->exec('UPDATE task_history SET created_at=' . (time() - 1800) . ' WHERE task_id=' . $id);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains(' (30 minutes ago)'), 'The comment timestamp carries the note.');
        $this->assertTrue(substr_count($page->body, '(30 minutes ago)') >= 2, 'The history rows carry the note too.');
    }

    public function test_the_chat_bubble_shows_how_long_ago(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=users-v2', [
            'action' => 'add_user',
            'username' => 'agoviewer',
            'display_name' => 'Ago Viewer',
            'password' => 'agoviewerPass12345',
            'role' => 'user',
        ]);
        $viewerId = (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='agoviewer'")->fetchColumn();

        // The real composer flow (#87): the one-time send token comes off the
        // composer page - a send without it is dropped by design.
        $composer = $admin->get('/?page=messages&u=' . $viewerId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders the send token.');
        $sent = $admin->post('/?page=messages&u=' . $viewerId, [
            'action' => 'send_message',
            'recipient_id' => (string)$viewerId,
            'body' => 'Aged bubble body',
            'send_token' => $m[1],
        ]);
        $this->assertSame(302, $sent->status);
        TestServer::pdo()->exec('UPDATE messages SET created_at=' . (time() - 120) . ' WHERE sender_id=' . $this->adminId() . ' AND recipient_id=' . $viewerId);

        $conversation = $admin->get('/?page=messages&u=' . $viewerId);
        $this->assertSame(200, $conversation->status);
        $this->assertTrue($conversation->contains('Aged bubble body'), 'The sent message renders in the thread.');
        $this->assertTrue($conversation->contains(' (2 minutes ago)'), 'The chat bubble renders the relative note.');
    }

    public function test_a_stamp_that_was_never_set_renders_never_without_a_suffix(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Never stamped task');
        TestServer::pdo()->exec('UPDATE tasks SET created_at=0, updated_at=0 WHERE id=' . $id);

        $page = $admin->taskPage($id);
        $this->assertSame(200, $page->status);
        // The detail line renders the bare never marker for both zeroed stamps.
        $this->assertTrue($page->contains('· never ·'), 'The never marker renders.');
        // 'never' must never be followed by an ago note - 'never (2 days ago)'
        // would mean the suffix ran on a stamp that was never set.
        $this->assertFalse($page->contains('never ('), 'A never stamp cannot render an ago note.');
    }

    private function adminId(): int
    {
        return (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='admin'")->fetchColumn();
    }
}
