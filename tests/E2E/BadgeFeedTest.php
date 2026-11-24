<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #64: the red navbar badges (messages, notifications) are
 * server-rendered at page load and used to go stale until a manual refresh.
 * Every page now carries a small footer poller plus a ?page=badges JSON
 * feed, so the badges move on their own while the board stays open. The
 * server-rendered badge markup is untouched - without JS the badges behave
 * exactly as before, and the feed is login-guarded like the badge sources.
 */
final class BadgeFeedTest extends E2eTestCase
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
        $s->closeCursor();
        return (int)$id;
    }

    /** Sends a message the way the composer does (#87): renders the thread
     * form, extracts its one-time send token and posts with it. */
    private function sendMessageFrom(AppSession $sender, int $recipientId, string $body): void
    {
        $composer = $sender->get('/?page=messages&u=' . $recipientId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders the one-time send token (#87).');
        $sender->post('/?page=messages&u=' . $recipientId, [
            'action' => 'send_message',
            'recipient_id' => (string)$recipientId,
            'body' => $body,
            'send_token' => $m[1],
        ]);
    }

    public function test_the_badge_feed_reports_the_current_counters(): void
    {
        $admin = $this->adminSession();
        $nora = $this->createRegularUser($admin, 'nora');
        $admin->createTask('Feed task', ['assignees' => [$this->userId('nora')]]);

        $feed = $nora->get('/?page=badges');
        $this->assertSame(200, $feed->status);
        $this->assertSame('application/json', strtok((string)$feed->header('content-type'), ';'), 'The feed answers as JSON.');
        $data = json_decode($feed->body, true);
        $this->assertSame(['messages', 'notifications'], array_keys($data));
        $this->assertSame(0, $data['messages'], 'No message was sent yet.');
        $this->assertSame(1, $data['notifications'], 'The task creation notified the assignee (#58).');

        $this->sendMessageFrom($admin, $this->userId('nora'), 'Live ping');

        $data = json_decode($nora->get('/?page=badges')->body, true);
        $this->assertSame(1, $data['messages'], 'The unread message shows up in the feed.');
        $this->assertSame(1, $data['notifications'], 'A private message does not create a notification.');
    }

    public function test_the_feed_clears_when_the_badge_sources_are_consumed(): void
    {
        $admin = $this->adminSession();
        $nadine = $this->createRegularUser($admin, 'nadine');
        $admin->createTask('Consume task', ['assignees' => [$this->userId('nadine')]]);
        $this->sendMessageFrom($admin, $this->userId('nadine'), 'Read me');

        // Opening the inbox consumes the message counter the same way a page
        // load has always done.
        $nadine->get('/?page=messages');
        $data = json_decode($nadine->get('/?page=badges')->body, true);
        $this->assertSame(0, $data['messages']);

        // Notifications clear whenever their rows turn seen - the exact
        // tables the navbar badge reads from. Flip them directly here so
        // this test stays independent of how the marking happens (the
        // per-item click-through of #73 does it in the UI).
        TestServer::pdo()->exec('UPDATE notifications SET is_seen=1 WHERE user_id=' . $this->userId('nadine'));
        $data = json_decode($nadine->get('/?page=badges')->body, true);
        $this->assertSame(0, $data['notifications']);
    }

    public function test_every_signed_in_page_carries_the_poller_and_the_feed_is_guarded(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?');
        $this->assertTrue($page->contains('?page=badges'), 'The navbar poller script is rendered on signed-in pages.');
        $this->assertTrue($page->contains('setInterval(tick,10000)'), 'The poller runs on the same 10s cadence as the list refresh.');
        $this->assertTrue($page->contains('bootstrap.bundle.min.js'), 'The bootstrap bundle still loads after the poller.');

        // Anonymous visitors get the standard login guard, no counters leak.
        $response = $this->session->get('/?page=badges');
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('page=login', (string)$response->header('location'));

        // The sign-in page has no poller: there is nothing to keep live there.
        $login = $this->session->get('/?page=login');
        $this->assertStringNotContainsString('?page=badges', $login->body);
    }
}
