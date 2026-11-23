<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #56: a simple message board - users leave messages to each other and
 * a red notification shows the number of NEW (unread) messages, both at the
 * top of the message board page and as a badge next to the navbar's Messages
 * link. Viewing the board marks the messages read, so the count always means
 * "new since the last visit". Messages survive user deletion (both ends are
 * ON DELETE SET NULL, the #17 "Deleted user" convention), and the schema
 * self-heals on existing boards like every other feature (the #24/#53
 * pattern - the #53 v2 lesson: the self-heal ships with the feature, tested).
 */
final class MessagesTest extends E2eTestCase
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

    public function test_the_message_board_round_trips_a_message(): void
    {
        $admin = $this->adminSession();
        $bob = $this->createRegularUser($admin, 'bobby');

        // The compose form offers every other user, not the sender themselves.
        $compose = $admin->get('/?page=messages');
        $this->assertSame(200, $compose->status);
        $this->assertTrue($compose->contains('<select class="form-select" id="message-recipient" name="recipient_id" required>'));
        $this->assertTrue($compose->contains('Bobby'), 'The recipient dropdown lists the other users.');
        $this->assertTrue($compose->contains('Administrator'), 'The other users are all offered.');

        $this->assertSame(302, $admin->post('/?page=messages', [
            'action' => 'send_message',
            'recipient_id' => $this->userId('bobby'),
            'body' => 'Welcome to the board, Bobby!',
        ])->status);

        // The recipient sees the message with its sender and body; the sender
        // sees it under Sent with the recipient's name.
        $inbox = $bob->get('/?page=messages');
        $this->assertTrue($inbox->contains('Welcome to the board, Bobby!'));
        $this->assertTrue($inbox->contains('Administrator'));
        $sent = $admin->get('/?page=messages');
        $this->assertTrue($sent->contains('to Bobby'));
        $this->assertTrue($sent->contains('Welcome to the board, Bobby!'));
    }

    public function test_the_red_notification_counts_new_messages_until_seen(): void
    {
        $admin = $this->adminSession();
        $carl = $this->createRegularUser($admin, 'carl');
        $carlId = $this->userId('carl');

        // Two unread messages: the red badge says 2 on the page and in the navbar.
        $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$carlId, 'body' => 'First note.']);
        $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$carlId, 'body' => 'Second note.']);

        $page = $carl->get('/?page=messages');
        $this->assertTrue($page->contains('<span class="badge text-bg-danger fs-6">2 new messages</span>'), 'The red notification shows the new message count.');
        $this->assertTrue($page->contains('badge text-bg-danger" title="New messages">2</span>'), 'The navbar badge shows the same count.');
        $this->assertTrue($page->contains('<span class="badge text-bg-danger">new</span>'), 'Unread rows are marked as new.');

        // Viewing the board marks them read: the badge resets, the rows stay.
        $again = $carl->get('/?page=messages');
        $this->assertTrue($again->contains('<span class="badge text-bg-danger fs-6">0 new messages</span>'));
        $this->assertTrue($again->contains('First note.'), 'The messages themselves stay on the board.');
        $this->assertFalse($again->contains('badge text-bg-danger" title="New messages"'), 'The navbar badge disappears when nothing is new.');
        $this->assertSame('2', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM messages WHERE recipient_id=' . $carlId . ' AND is_read=1')->fetchColumn());
    }

    public function test_marking_read_is_per_recipient(): void
    {
        $admin = $this->adminSession();
        $dora = $this->createRegularUser($admin, 'dora');
        $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$this->userId('dora'), 'body' => 'For Dora only.']);

        // The sender viewing their own board must not consume the recipient's
        // unread count, and the message must not appear in the sender's inbox.
        $admin->get('/?page=messages');
        $remaining = (int)TestServer::pdo()->query('SELECT COUNT(*) FROM messages WHERE recipient_id=' . $this->userId('dora') . ' AND is_read=0')->fetchColumn();
        $this->assertSame(1, $remaining, 'The recipient\'s badge is untouched by other people\'s visits.');
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM messages WHERE sender_id=1 AND recipient_id=1')->fetchColumn(), 'The message sits in exactly one inbox.');
    }

    public function test_sending_requires_a_real_recipient_and_a_body(): void
    {
        $admin = $this->adminSession();
        $frank = $this->createRegularUser($admin, 'frank');
        $frankId = $this->userId('frank');

        $empty = $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$frankId, 'body' => '   ']);
        $this->assertTrue($empty->contains('The message cannot be empty.'));

        $unknown = $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => '99999', 'body' => 'Hello?']);
        $this->assertTrue($unknown->contains('Pick a recipient.'));

        $self = $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$this->userId('admin'), 'body' => 'A note to myself.']);
        $this->assertTrue($self->contains('Pick another user to message.'));
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM messages')->fetchColumn(), 'Nothing was stored by the rejected sends.');
    }

    public function test_messages_survive_user_deletion_as_deleted_user(): void
    {
        $admin = $this->adminSession();
        $erin = $this->createRegularUser($admin, 'erin');
        $erinId = $this->userId('erin');
        $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$erinId, 'body' => 'Erin, please archive the old notes.']);
        $erin->get('/?page=messages');

        $admin->post('/?page=users', ['action' => 'delete_user', 'id' => (string)$erinId]);
        // The admin keeps the conversation with the #17 convention.
        $sent = $admin->get('/?page=messages');
        $this->assertTrue($sent->contains('to Deleted user'), 'The recipient renders as Deleted user.');
        $this->assertTrue($sent->contains('Erin, please archive the old notes.'));
    }

    private function userId(string $username): int
    {
        return (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='" . $username . "'")->fetchColumn();
    }
}
