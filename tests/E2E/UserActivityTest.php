<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use PDO;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #30: the board tracks when a user was last active. Every authenticated
 * request refreshes users.last_seen_at (the write is throttled inside
 * tb_touch_last_seen — see the constant there for why), the schema self-heals
 * on deployments that land without the upgrade SQL, and the admin page renders
 * the stamp as 'active: ...' ('never' for a user not seen since the feature
 * landed).
 */
final class UserActivityTest extends E2eTestCase
{
    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'User ' . $username . ' does not exist.');
        return (int)$id;
    }

    private function lastSeen(int $userId): ?string
    {
        $s = TestServer::pdo()->prepare('SELECT last_seen_at FROM users WHERE id=?');
        $s->execute([$userId]);
        $v = $s->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Ages the stamp on the database's own clock, like tb_touch_last_seen does. */
    private function backdateMinutes(int $userId, int $minutes): void
    {
        $pdo = TestServer::pdo();
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'UPDATE users SET last_seen_at = NOW() - INTERVAL ' . $minutes . ' MINUTE WHERE id = ?'
            : "UPDATE users SET last_seen_at = datetime('now', '-" . $minutes . " minutes') WHERE id = ?";
        $pdo->prepare($sql)->execute([$userId]);
    }

    public function test_an_authenticated_request_stamps_last_seen_and_a_fresh_install_starts_empty(): void
    {
        $admin = $this->adminSession();
        $id = $this->userId('admin');
        $this->assertNull($this->lastSeen($id), 'A fresh install has never seen anyone.');

        $page = $admin->get('/?page=tasks');
        $this->assertSame(200, $page->status);
        $this->assertNotNull($this->lastSeen($id), 'The first authenticated request must stamp last_seen_at.');
    }

    public function test_the_refresh_is_throttled_to_the_minimum_interval(): void
    {
        $admin = $this->adminSession();
        $id = $this->userId('admin');
        $admin->get('/?page=tasks');   // the first authenticated request: stamps now
        $first = $this->lastSeen($id);
        $this->assertNotNull($first);

        $admin->get('/?page=tasks');   // seconds later: inside the two-minute window
        $this->assertSame($first, $this->lastSeen($id), 'A request inside the interval must not rewrite the stamp.');

        $this->backdateMinutes($id, 10);   // ten minutes old: outside the window
        $stale = $this->lastSeen($id);
        // The refresh is asserted against the backdated stamp, not $first: the
        // stamps carry one-second resolution and this whole scenario (three
        // requests over loopback) runs inside one wall-clock second, so the
        // refreshed write may legitimately equal $first while still being a
        // real refresh ten minutes newer than the stale value.
        $admin->get('/?page=tasks');
        $this->assertNotSame($stale, $this->lastSeen($id), 'A request outside the interval must refresh the stamp.');
    }

    public function test_the_admin_page_shows_the_last_active_column(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => 'jane',
            'display_name' => 'Jane Doe',
            'password' => 'JanePass12345',
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status);

        // Jane has never logged in: her row reads 'never'. The admin has just
        // requested pages: the label is fresh, and this board is minute
        // resolution, so only the 'active: ' prefix is asserted here — the
        // exact wording lives in tests/Unit/LastSeenLabelTest.php.
        $page = $admin->get('/?page=users');
        $this->assertSame(200, $page->status);
        // #57 made the column sortable: the header is now a link carrying the
        // aria-label instead of a plain <th>.
        $this->assertTrue($page->contains('Order by Last active'), 'The users table must have the Last active column.');
        $this->assertTrue($page->contains('>never</td>'), 'A user that has not been seen must read never.');
        $this->assertTrue($page->contains('active: '), 'A signed-in user must show a fresh active label.');
    }

    public function test_a_deployment_without_the_column_self_heals_on_the_first_request(): void
    {
        $pdo = TestServer::pdo();
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        // last_seen_at carries no constraint or index, so both dialects drop it
        // in place (no table rebuild needed, unlike the #24 projects fixture).
        $pdo->exec('ALTER TABLE users DROP COLUMN last_seen_at');
        if ($isMysql) {
            $cols = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } else {
            $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        $this->assertNotContains('last_seen_at', $cols, 'Fixture: the column must be gone before the request.');

        // The very first request (the installer page here) runs the self-heal,
        // and everything after it works normally — including the stamping.
        $admin = $this->adminSession();
        $page = $admin->get('/?page=tasks');
        $this->assertSame(200, $page->status);
        $this->assertNotNull($this->lastSeen($this->userId('admin')), 'The healed column must be written again like a fresh install.');
    }
    /**
     * Task #30 (v2): the dot renders next to every user name — the navbar,
     * the task list assignee cell, the task detail creator/assignee/comment
     * author and the admin page. jane is created and never signs in, so she
     * is the red case; the admin just made requests, so they are the green
     * one. The exact color boundaries live in tests/Unit/ActivityStatusTest.php.
     */
    public function test_the_activity_dot_shows_next_to_user_names_everywhere(): void
    {
        $admin = $this->adminSession();
        $admin->get('/?page=tasks');   // stamp the admin as active
        $response = $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => 'jane',
            'display_name' => 'Jane Doe',
            'password' => 'JanePass12345',
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status);
        $janeId = $this->userId('jane');
        $taskId = $admin->createTask('Dot test task', ['assignees' => [(string)$janeId]]);

        // Navbar: the signed-in viewer just made requests — green next to their name.
        $list = $admin->get('/?page=tasks');
        $this->assertSame(200, $list->status);
        $this->assertTrue(
            $list->contains('navbar-text mx-2"><span class="tb-activity-dot tb-activity-green"'),
            'The signed-in user must carry a green dot in the navbar.'
        );
        // Task list: the assignee cell renders dot + name (jane is red, never seen).
        $this->assertTrue(
            $list->contains('tb-activity-red" title="Inactive for over an hour"></span>Jane Doe'),
            'Assignee names on the task list must carry the activity dot.'
        );

        // Task detail: creator, assignee and comment author all render with dots.
        $comment = $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'comment',
            'task_id' => (string)$taskId,
            'body' => 'A comment from the active admin.',
        ]);
        $this->assertSame(302, $comment->status);
        $detail = $admin->taskPage($taskId);
        $this->assertSame(200, $detail->status);
        $this->assertTrue(
            $detail->contains('Created by <span class="tb-activity-dot tb-activity-green"'),
            'The creator must carry the activity dot.'
        );
        $this->assertTrue(
            $detail->contains('tb-activity-red" title="Inactive for over an hour"></span>Jane Doe'),
            'The assignee on the detail page must carry the dot.'
        );
        $this->assertTrue(
            $detail->contains('<strong><span class="tb-activity-dot tb-activity-green"'),
            'The comment author must carry the dot.'
        );

        // Admin page: the users table pairs the dot with each name (the
        // Last active column of v1 stays alongside).
        $adminPage = $admin->get('/?page=users');
        $this->assertSame(200, $adminPage->status);
        $this->assertTrue(
            $adminPage->contains('tb-activity-red" title="Inactive for over an hour"></span>Jane Doe'),
            'A never-seen user must show a red dot.'
        );
        $this->assertTrue(
            $adminPage->contains('tb-activity-green" title="Active in the last 15 minutes"></span>Administrator'),
            'The active admin must show a green dot.'
        );
    }
}
