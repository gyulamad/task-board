<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #100: the notifications page carries a switch that shows or hides the
 * red "New notifications" badge in the top menu. The preference is per user
 * (the #66 user-preferences table), defaults to ON so the board behaves
 * exactly like before, and gates BOTH badge sources: the server-rendered
 * navbar badge and the ?page=badges JSON feed the footer poller ( #64 )
 * swaps the badge with every 10 seconds. Gating only one of them would let
 * the other resurrect the badge immediately.
 *
 * Scope guard: only the top-menu flag is switched. The notifications page
 * itself keeps its stream counter, and the messages badge is untouched.
 */
final class NotificationFlagToggleTest extends E2eTestCase
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

    private function feed(AppSession $session): array
    {
        $response = $session->get('/?page=badges');
        $this->assertSame(200, $response->status);
        return json_decode($response->body, true);
    }

    public function test_the_toggle_renders_checked_by_default_and_the_flag_shows(): void
    {
        $admin = $this->adminSession();
        $nora = $this->createRegularUser($admin, 'nora');
        $admin->createTask('Flag task', ['assignees' => [$this->userId('nora')]]);

        // Badge assertions first: opening the notifications page marks the
        // stream seen (#79), which would clear the badge for unrelated
        // reasons - so the default-ON flag is pinned before that visit.
        $tasks = $nora->get('/?page=tasks-v2');
        $this->assertTrue($tasks->contains('title="New notifications"'), 'With the default ON the red flag renders in the top menu.');
        $this->assertSame(1, $this->feed($nora)['notifications'], 'The feed reports the unseen notification.');

        $stream = $nora->get('/?page=notifications');
        $this->assertSame(200, $stream->status);
        $this->assertTrue($stream->contains('id="notification-flag-toggle"'), 'The switch renders at the top of the notifications page.');
        $this->assertTrue($stream->contains('Red flag in the top menu'), 'The switch is labelled.');
        $this->assertTrue($stream->contains('role="switch"'), 'The switch speaks the Bootstrap switch dialect.');
        $this->assertMatchesRegularExpression('/id="notification-flag-toggle"[^>]*checked/', $stream->body, 'The default state is ON - the badge shows until the user switches it off.');
    }

    public function test_toggling_off_hides_the_flag_but_not_the_stream_or_messages_badge(): void
    {
        $admin = $this->adminSession();
        $nora = $this->createRegularUser($admin, 'nadia');
        $admin->createTask('Hide task', ['assignees' => [$this->userId('nadia')]]);

        // The switch posts its form back to the notifications page; the
        // action must land the user right back there (#100 flow).
        $response = $nora->post('/?page=notifications', ['action' => 'toggle_notification_flag']);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('page=notifications', (string)$response->header('location'), 'The toggle returns to the page the switch was used on (either URL renders the stream since #85).');

        // Unseen rows still exist - only the top-menu flag is switched off.
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM notifications WHERE user_id=' . $this->userId('nadia') . ' AND is_seen=0')->fetchColumn());

        $tasks = $nora->get('/?page=tasks-v2');
        $this->assertFalse($tasks->contains('title="New notifications"'), 'The top menu renders no notification badge while the flag is off.');

        $this->sendMessageFrom($admin, $this->userId('nadia'), 'Still visible');
        $data = $this->feed($nora);
        $this->assertSame(0, $data['notifications'], 'The feed reports zero for a switched-off flag, so the 10s poller cannot resurrect the badge.');
        $this->assertSame(1, $data['messages'], 'Only the notification flag is switched - the messages badge keeps working.');

        // The switch state survives a reload and renders unchecked now; the
        // stream itself is untouched (its counter is the page header badge,
        // not the top-menu flag).
        $stream = $nora->get('/?page=notifications');
        $this->assertTrue($stream->contains('id="notification-flag-toggle"'));
        $this->assertDoesNotMatchRegularExpression('/id="notification-flag-toggle"[^>]*checked/', $stream->body, 'The switch renders unchecked after the user switched the flag off.');
    }

    public function test_toggling_on_again_restores_the_flag(): void
    {
        $admin = $this->adminSession();
        $nora = $this->createRegularUser($admin, 'nils');
        $nora->post('/?page=notifications', ['action' => 'toggle_notification_flag']);
        $this->assertFalse($nora->get('/?page=tasks-v2')->contains('title="New notifications"'));

        $nora->post('/?page=notifications', ['action' => 'toggle_notification_flag']);
        $admin->createTask('Restore task', ['assignees' => [$this->userId('nils')]]);

        $this->assertTrue($nora->get('/?page=tasks-v2')->contains('title="New notifications"'), 'The flag renders again after switching it back on.');
        $this->assertSame(1, $this->feed($nora)['notifications'], 'The feed reports the unseen notification again.');
    }

    public function test_the_preference_is_per_user_and_survives_a_new_sign_in(): void
    {
        $admin = $this->adminSession();
        $nora = $this->createRegularUser($admin, 'nora2');
        $milo = $this->createRegularUser($admin, 'milo');
        $nora->post('/?page=notifications', ['action' => 'toggle_notification_flag']);

        // Two unseen notifications, one per user: milo never touched the
        // switch, nora switched the flag off. Assignment notifications (#58)
        // only fire for other users' events, so both tasks come from the
        // admin.
        $admin->createTask('Milo task', ['assignees' => [$this->userId('milo')]]);
        $admin->createTask('Nora task', ['assignees' => [$this->userId('nora2')]]);

        $this->assertTrue($milo->get('/?page=tasks-v2')->contains('title="New notifications"'), 'Milo, who never touched the switch, still sees the flag.');
        $this->assertFalse($nora->get('/?page=tasks-v2')->contains('title="New notifications"'), 'Nora, who switched the flag off, does not.');

        // Nora's unseen row exists - it is the flag hiding the badge, not
        // missing data. closeCursor drains the statement so the SQLite test
        // connection never keeps a lock across the following HTTP request
        // (the #61 v3 lesson).
        $c = TestServer::pdo()->query('SELECT COUNT(*) FROM notifications WHERE user_id=' . $this->userId('nora2') . ' AND is_seen=0');
        $this->assertSame(1, (int)$c->fetchColumn());
        $c->closeCursor();

        // The preference lives in the user_preferences table (the #66
        // discipline), so a brand-new session for the same user keeps it.
        $s = TestServer::pdo()->prepare('SELECT pref_value FROM user_preferences WHERE user_id=? AND pref_key=?');
        $s->execute([$this->userId('nora2'), 'notification_flag_enabled']);
        $this->assertSame('0', (string)$s->fetchColumn());
        $s->closeCursor();

        $fresh = new AppSession('nora2', 'nora2Pass12345');
        $this->assertFalse($fresh->get('/?page=tasks-v2')->contains('title="New notifications"'), 'A new sign-in for the same user keeps the flag hidden.');
        $this->assertSame(0, $this->feed($fresh)['notifications'], 'The feed honours the stored preference for the new session too.');
    }
}
