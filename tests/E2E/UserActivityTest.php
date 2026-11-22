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
        $admin->get('/?page=tasks');
        $this->assertNotSame($first, $this->lastSeen($id), 'A request outside the interval must refresh the stamp.');
    }

    public function test_the_admin_page_shows_the_last_active_column(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=admin', [
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
        $page = $admin->get('/?page=admin');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('<th>Last active</th>'), 'The users table must have the Last active column.');
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
}
