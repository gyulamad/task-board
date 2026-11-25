<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\HttpResponse;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #122: the administrator can delete messages - a single message, a
 * selection of messages, or a whole conversation. Single users are
 * restricted from deleting: they are rendered no controls and every delete
 * action rejects them server-side. A deleted message takes its stored
 * attachment file with it (no row, no orphan file), the "Deleted user"
 * orphan thread (#17) is deletable like any other conversation, and other
 * people's conversations stay untouched.
 */
final class MessagesDeleteTest extends E2eTestCase
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

    /** Sends a message the way the composer does (#87): renders the thread
     * form, extracts its one-time send token and posts with it. */
    private function sendMessageFrom(AppSession $sender, int $recipientId, string $body): HttpResponse
    {
        $composer = $sender->get('/?page=messages&u=' . $recipientId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders the one-time send token (#87).');
        return $sender->post('/?page=messages&u=' . $recipientId, [
            'action' => 'send_message',
            'recipient_id' => (string)$recipientId,
            'body' => $body,
            'send_token' => $m[1],
        ]);
    }

    private function messageIdsByBody(string $body): array
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM messages WHERE body=? ORDER BY id');
        $s->execute([$body]);
        $rows = $s->fetchAll(\PDO::FETCH_COLUMN);
        $s->closeCursor();
        return array_map('intval', $rows);
    }

    private function totalMessages(): int
    {
        return (int)TestServer::pdo()->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    }

    public function test_only_the_admin_sees_the_delete_controls(): void
    {
        $admin = $this->adminSession();
        $zoltan = $this->createRegularUser($admin, 'zoltan');
        $zoltanId = $this->userId('zoltan');
        $this->sendMessageFrom($admin, $zoltanId, 'Keep me.');

        // The admin's thread carries the bulk form, the whole-conversation
        // button and the per-message controls (checkbox + delete button).
        $thread = $admin->get('/?page=messages&u=' . $zoltanId);
        $this->assertTrue($thread->contains('id="tb-chat-bulk-delete"'), 'The bulk delete form renders for the admin.');
        $this->assertTrue($thread->contains('value="delete_messages"'), 'The bulk action is wired.');
        $this->assertTrue($thread->contains('value="delete_conversation"'), 'The whole-conversation action is wired.');
        $this->assertTrue($thread->contains('name="ids[]"'), 'The bubbles carry the select checkboxes.');
        $this->assertTrue($thread->contains('value="delete_message"'), 'The per-message delete button renders.');

        // The single user's view of the same conversation carries none of it.
        $plain = $zoltan->get('/?page=messages&u=' . $this->userId('admin'));
        $this->assertTrue($plain->contains('Keep me.'), 'The regular user still sees the message.');
        $this->assertFalse($plain->contains('id="tb-chat-bulk-delete"'), 'No bulk form for a single user.');
        $this->assertFalse($plain->contains('value="delete_conversation"'), 'No conversation delete for a single user.');
        $this->assertFalse($plain->contains('name="ids[]"'), 'No select checkboxes for a single user.');
        $this->assertFalse($plain->contains('value="delete_message"'), 'No per-message delete for a single user.');

        // The live feed keeps the same rule: the admin's appended bubbles
        // carry the controls, the single user's feed bubbles stay clean.
        $adminFeed = json_decode((string)$admin->get('/?page=messages_live&u=' . $zoltanId . '&after=0')->body, true);
        $this->assertStringContainsString('value="delete_message"', (string)$adminFeed['html'], 'The live feed renders admin controls.');
        $plainFeed = json_decode((string)$zoltan->get('/?page=messages_live&u=' . $this->userId('admin') . '&after=0')->body, true);
        $this->assertStringNotContainsString('value="delete_message"', (string)$plainFeed['html'], 'The single user\'s live feed stays clean.');
    }

    public function test_the_admin_deletes_a_single_message_of_the_conversation(): void
    {
        $admin = $this->adminSession();
        $pal = $this->createRegularUser($admin, 'pal');
        $palId = $this->userId('pal');
        $this->sendMessageFrom($admin, $palId, 'Delete me.');
        $this->sendMessageFrom($admin, $palId, 'Keep me.');
        $this->sendMessageFrom($pal, $this->userId('admin'), 'Pal speaking.');

        // The per-message form of the bubble names the message id; posting it
        // removes exactly that message and lands back in the conversation.
        $thread = $admin->get('/?page=messages&u=' . $palId);
        $this->assertSame(1, preg_match('/name="csrf" value="([0-9a-f]{64})"><input type="hidden" name="action" value="delete_message"><input type="hidden" name="message_id" value="(\d+)"/', $thread->body, $mm), 'The per-message delete form carries the message id.');
        $deleteId = (int)$mm[2];
        $this->assertSame('Delete me.', (string)TestServer::pdo()->query('SELECT body FROM messages WHERE id=' . $deleteId)->fetchColumn(), 'The matched bubble is the first message.');

        $response = $admin->post('/?page=messages&u=' . $palId, ['action' => 'delete_message', 'message_id' => (string)$deleteId]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=messages&u=' . $palId, $response->header('location'), 'The redirect lands back in the conversation.');
        $this->assertSame(2, $this->totalMessages(), 'Exactly the one message is gone.');
        $this->assertFalse($admin->get('/?page=messages&u=' . $palId)->contains('Delete me.'), 'The thread no longer shows the deleted message.');
        $this->assertTrue($admin->get('/?page=messages&u=' . $palId)->contains('Pal speaking.'), 'The rest of the conversation stays.');

        // An unknown message id is a clean validation error, not a wipe.
        $missing = $admin->post('/?page=messages&u=' . $palId, ['action' => 'delete_message', 'message_id' => '999999']);
        $this->assertTrue($missing->contains('Message not found.'));
    }

    public function test_the_admin_can_delete_the_peer_bubble_too(): void
    {
        $admin = $this->adminSession();
        $pal = $this->createRegularUser($admin, 'quinn');
        $palId = $this->userId('quinn');
        $this->sendMessageFrom($pal, $this->userId('admin'), 'From the peer.');

        // The delete controls render on every bubble of the thread, the
        // peer's own bubble included - the admin moderates the conversation
        // they are looking at, not just their own half.
        $thread = $admin->get('/?page=messages&u=' . $palId);
        $this->assertTrue($thread->contains('From the peer.'));
        $this->assertSame(1, preg_match('/name="message_id" value="(\d+)"[^>]*><button class="btn btn-link text-danger p-0/', $thread->body, $m), 'The peer bubble carries the delete form.');
        $response = $admin->post('/?page=messages&u=' . $palId, ['action' => 'delete_message', 'message_id' => $m[1]]);
        $this->assertSame(302, $response->status);
        $this->assertSame(0, $this->totalMessages(), 'The peer message is gone.');
    }

    public function test_single_users_are_restricted_from_deleting(): void
    {
        $admin = $this->adminSession();
        $ruby = $this->createRegularUser($admin, 'ruby');
        $rubyId = $this->userId('ruby');
        $this->sendMessageFrom($admin, $rubyId, 'Protected note.');
        $msgId = $this->messageIdsByBody('Protected note.')[0];

        // The three delete actions reject a non-admin with the same message,
        // and nothing is deleted.
        $one = $ruby->post('/?page=messages&u=' . $this->userId('admin'), ['action' => 'delete_message', 'message_id' => (string)$msgId]);
        $this->assertTrue($one->contains('Only an administrator can delete messages.'), 'The single-message delete rejects a single user.');
        $bulk = $ruby->post('/?page=messages&u=' . $this->userId('admin'), ['action' => 'delete_messages', 'ids' => [(string)$msgId], 'u' => (string)$this->userId('admin')]);
        $this->assertTrue($bulk->contains('Only an administrator can delete messages.'), 'The bulk delete rejects a single user.');
        $whole = $ruby->post('/?page=messages&u=' . $this->userId('admin'), ['action' => 'delete_conversation', 'u' => (string)$this->userId('admin')]);
        $this->assertTrue($whole->contains('Only an administrator can delete messages.'), 'The conversation delete rejects a single user.');
        $this->assertSame(1, $this->totalMessages(), 'The message survived every rejected attempt.');

        // An empty bulk selection is a validation error for the admin too.
        $empty = $admin->post('/?page=messages&u=' . $rubyId, ['action' => 'delete_messages', 'u' => (string)$rubyId]);
        $this->assertTrue($empty->contains('Select the messages to delete.'), 'No ids means nothing to delete.');
        $this->assertSame(1, $this->totalMessages());
    }

    public function test_the_admin_deletes_selected_messages_in_bulk(): void
    {
        $admin = $this->adminSession();
        $sam = $this->createRegularUser($admin, 'sam');
        $samId = $this->userId('sam');
        $this->sendMessageFrom($admin, $samId, 'Bulk one.');
        $this->sendMessageFrom($admin, $samId, 'Bulk two.');
        $this->sendMessageFrom($admin, $samId, 'Survivor.');
        $ids = array_merge($this->messageIdsByBody('Bulk one.'), $this->messageIdsByBody('Bulk two.'), $this->messageIdsByBody('Survivor.'));

        // Both selected messages go in one submit; the unselected one stays.
        $response = $admin->post('/?page=messages&u=' . $samId, [
            'action' => 'delete_messages',
            'u' => (string)$samId,
            'ids' => [(string)$ids[0], (string)$ids[1]],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=messages&u=' . $samId, $response->header('location'), 'Back to the same conversation.');
        $this->assertSame(1, $this->totalMessages(), 'Exactly the two selected messages are gone.');
        $thread = $admin->get('/?page=messages&u=' . $samId);
        $this->assertFalse($thread->contains('Bulk one.'));
        $this->assertFalse($thread->contains('Bulk two.'));
        $this->assertTrue($thread->contains('Survivor.'), 'The unselected message stays.');
    }

    public function test_the_admin_deletes_the_whole_conversation(): void
    {
        $admin = $this->adminSession();
        $tina = $this->createRegularUser($admin, 'tina');
        $tinaId = $this->userId('tina');
        $ursula = $this->createRegularUser($admin, 'ursula');
        $ursulaId = $this->userId('ursula');
        $this->sendMessageFrom($admin, $tinaId, 'Tina thread A.');
        $this->sendMessageFrom($tina, $this->userId('admin'), 'Tina thread B.');
        $this->sendMessageFrom($admin, $ursulaId, 'Ursula thread.');

        // The whole-conversation submit wipes the pair's messages only: the
        // other conversation of the board stays untouched.
        $response = $admin->post('/?page=messages&u=' . $tinaId, ['action' => 'delete_conversation', 'u' => (string)$tinaId]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=messages&u=' . $tinaId, $response->header('location'), 'The admin stays in the emptied thread.');
        $this->assertSame(1, $this->totalMessages(), 'Only the Ursula message survives.');
        $emptied = $admin->get('/?page=messages&u=' . $tinaId);
        $this->assertTrue($emptied->contains('No messages here yet'), 'The emptied thread renders the empty state.');
        $this->assertFalse($emptied->contains('Tina thread A.'));
        $this->assertTrue($admin->get('/?page=messages&u=' . $ursulaId)->contains('Ursula thread.'), 'The other conversation is untouched.');

        // An already empty conversation is a validation error, not a crash.
        $again = $admin->post('/?page=messages&u=' . $tinaId, ['action' => 'delete_conversation', 'u' => (string)$tinaId]);
        $this->assertTrue($again->contains('The conversation is already empty.'));
    }

    public function test_the_deleted_user_thread_is_deletable_too(): void
    {
        $admin = $this->adminSession();
        $vera = $this->createRegularUser($admin, 'vera');
        $veraId = $this->userId('vera');
        $this->sendMessageFrom($admin, $veraId, 'Vera will be gone.');
        $admin->post('/?page=users', ['action' => 'delete_user', 'id' => (string)$veraId]);

        // The orphan thread (#17) is a conversation like any other: the u=0
        // thread renders the controls and the whole-conversation delete with
        // u=0 wipes exactly it.
        $thread = $admin->get('/?page=messages&u=0');
        $this->assertTrue($thread->contains('Vera will be gone.'), 'The orphan thread still shows the message.');
        $this->assertTrue($thread->contains('value="delete_conversation"'), 'The orphan thread carries the admin controls.');
        $response = $admin->post('/?page=messages&u=0', ['action' => 'delete_conversation', 'u' => '0']);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=messages', $response->header('location'), 'An orphan wipe lands back on the people list.');
        $this->assertSame(0, $this->totalMessages(), 'The orphan messages are gone.');
        $this->assertFalse($admin->get('/?page=messages&u=0')->contains('Vera will be gone.'));
    }

    public function test_deleting_a_message_removes_its_stored_attachment_file(): void
    {
        $admin = $this->adminSession();
        $walter = $this->createRegularUser($admin, 'walter');
        $walterId = $this->userId('walter');

        // A message with a real attachment: the file lands in uploads/ with a
        // random stored name (#94). A text-only neighbour marks the
        // capture-first discipline: its own (non-existent) attachments must
        // not crash the delete.
        $composer = $admin->get('/?page=messages&u=' . $walterId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m));
        $tmp = tempnam(sys_get_temp_dir(), 'tbdel') . '.txt';
        file_put_contents($tmp, 'attachment payload for the delete test');
        $sent = $admin->upload('/?page=messages&u=' . $walterId, [
            'action' => 'send_message',
            'recipient_id' => (string)$walterId,
            'body' => 'With an attachment.',
            'send_token' => $m[1],
        ], 'attachment', $tmp, 'board-notes.txt', 'text/plain');
        $this->assertSame(302, $sent->status, 'The attachment send goes through.');
        $this->sendMessageFrom($admin, $walterId, 'Text only neighbour.');

        $row = TestServer::pdo()->query('SELECT a.id, a.stored_name, m.id mid FROM message_attachments a JOIN messages m ON m.id=a.message_id')->fetch();
        $this->assertNotFalse($row, 'The attachment row exists.');
        $storedFile = TestServer::docRoot() . '/uploads/' . $row['stored_name'];
        $this->assertFileExists($storedFile, 'The stored file exists before the delete.');

        // Deleting the message takes the row (cascade) and the file with it;
        // the neighbouring text-only message stays.
        $response = $admin->post('/?page=messages&u=' . $walterId, ['action' => 'delete_message', 'message_id' => (string)$row['mid']]);
        $this->assertSame(302, $response->status);
        $this->assertSame(1, $this->totalMessages(), 'Only the attachment message is gone.');
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM message_attachments')->fetchColumn(), 'The attachment row cascaded away.');
        $this->assertFileDoesNotExist($storedFile, 'The stored file went with the message.');
        $this->assertTrue($admin->get('/?page=messages&u=' . $walterId)->contains('Text only neighbour.'));
        @unlink($tmp);
    }
}
