<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #56: a message board - users leave messages to each other and a red
 * notification shows the number of NEW (unread) messages, both at the top of
 * the page and as a badge next to the navbar's Messages link. Viewing the
 * board marks the messages read, so the count always means "new since the
 * last visit".
 * Task #65: the board renders as a messenger - every other user on the left
 * with their unread count, the thread with the selected person as chat
 * bubbles on the right (mine carry the sending time and the Seen/Sent
 * status), the sender form under the thread, and a "Deleted user" entry that
 * keeps the #17 convention readable.
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

    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $rows = $s->fetchAll(\PDO::FETCH_COLUMN);
        $s->closeCursor();
        $this->assertNotEmpty($rows, 'User ' . $username . ' does not exist.');
        return (int)$rows[0];
    }

    public function test_the_messenger_round_trips_a_message_with_seen_status(): void
    {
        $admin = $this->adminSession();
        $bob = $this->createRegularUser($admin, 'bobby');
        $bobId = $this->userId('bobby');

        // The left people list offers every other user with their activity
        // dot; the sender form is bound to the selected person.
        $compose = $admin->get('/?page=messages');
        $this->assertSame(200, $compose->status);
        $this->assertTrue($compose->contains('href="?page=messages&u=' . $bobId . '"'), 'The people list links the other user.');
        $this->assertTrue($compose->contains('Bobby'), 'The people list shows the other users.');
        $this->assertTrue($compose->contains('name="recipient_id" value="' . $bobId . '"'), 'The thread form is bound to the selected person.');

        $this->assertSame(302, $admin->post('/?page=messages', [
            'action' => 'send_message',
            'recipient_id' => $bobId,
            'body' => 'Welcome to the board, Bobby!',
        ])->status);

        // The recipient's default thread is the newest unread sender's: the
        // message shows as their bubble with the sending time.
        $inbox = $bob->get('/?page=messages');
        $this->assertTrue($inbox->contains('Conversation with Administrator'));
        $this->assertTrue($inbox->contains('Welcome to the board, Bobby!'));

        // Once the recipient has opened the board, the sender's bubble flips
        // from Sent to Seen (#65) in the sender's own thread.
        $sent = $admin->get('/?page=messages&u=' . $bobId);
        $this->assertTrue($sent->contains('Welcome to the board, Bobby!'));
        $this->assertTrue($sent->contains('· Seen'), 'The sender sees the message was read.');
        $this->assertTrue($sent->contains('justify-content-end'), 'Own messages sit on the right like chat bubbles.');
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
        $this->assertTrue($page->contains('<span class="badge text-bg-danger">2</span>'), 'The people list counts the unread messages per person.');

        // Viewing the board marks them read: the badge resets, the thread stays.
        $again = $carl->get('/?page=messages');
        $this->assertTrue($again->contains('<span class="badge text-bg-danger fs-6">0 new messages</span>'));
        $this->assertTrue($again->contains('First note.'), 'The messages themselves stay in the thread.');
        $this->assertFalse($again->contains('badge text-bg-danger" title="New messages"'), 'The navbar badge disappears when nothing is new.');
        $this->assertSame('2', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM messages WHERE recipient_id=' . $carlId . ' AND is_read=1')->fetchColumn());
    }

    public function test_the_thread_opens_on_the_conversation_with_recent_activity(): void
    {
        $admin = $this->adminSession();
        $helga = $this->createRegularUser($admin, 'helga');
        $ingrid = $this->createRegularUser($admin, 'ingrid');
        $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$this->userId('helga'), 'body' => 'For Helga.']);

        // The newest unread sender wins the default thread, even though
        // Helga sorts before Ingrid... and Ingrid before nobody. Helga opens
        // her board: the unread one is from Administrator.
        $helgaBoard = $helga->get('/?page=messages');
        $this->assertTrue($helgaBoard->contains('Conversation with Administrator'), 'The default thread is the newest unread sender.');
        $this->assertTrue($helgaBoard->contains('For Helga.'));

        // A visitor with no messages at all lands on the first person.
        $board = $ingrid->get('/?page=messages');
        $this->assertTrue($board->contains('No messages here yet'), 'An empty thread says so instead of rendering nothing.');
    }

    public function test_marking_read_is_per_recipient(): void
    {
        $admin = $this->adminSession();
        $dora = $this->createRegularUser($admin, 'dora');
        $admin->post('/?page=messages', ['action' => 'send_message', 'recipient_id' => (string)$this->userId('dora'), 'body' => 'For Dora only.']);

        // The sender viewing their own board must not consume the recipient's
        // unread count.
        $admin->get('/?page=messages');
        $remaining = (int)TestServer::pdo()->query('SELECT COUNT(*) FROM messages WHERE recipient_id=' . $this->userId('dora') . ' AND is_read=0')->fetchColumn();
        $this->assertSame(1, $remaining, 'The recipient\'s badge is untouched by other people\'s visits.');
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
        // Erin never opens the board: viewing it consumes her unread messages
        // (the page-view mark-seen), which would legitimately flip the bubble
        // to Seen before the deletion. The Sent-forever pin below is only
        // meaningful for a message the recipient never consumed.

        $admin->post('/?page=users', ['action' => 'delete_user', 'id' => (string)$erinId]);
        // The messenger keeps the conversation with the #17 convention: a
        // "Deleted user" entry appears and the thread stays readable, with
        // the sender's own bubble stuck on Sent forever.
        $board = $admin->get('/?page=messages');
        $this->assertTrue($board->contains('>Deleted user</span>'), 'The people list carries the Deleted user entry.');
        $thread = $admin->get('/?page=messages&u=0');
        $this->assertTrue($thread->contains('Conversation with Deleted user') || $thread->contains('<h2 class="h5">Deleted user</h2>'), 'The deleted-user thread opens.');
        $this->assertTrue($thread->contains('Erin, please archive the old notes.'));
        $this->assertTrue($thread->contains('· Sent'), 'A deleted recipient can never mark the message seen.');
    }
}
