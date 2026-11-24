<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #94: the chat UX of the messenger - the thread fills the page height
 * and scrolls, opens on the last 20 messages and pulls older pages through
 * the messages_more feed while scrolling up, the composer is pinned to the
 * bottom with Enter-to-send and an attachment button (one file per message,
 * downloadable only by the conversation partners), the people list orders by
 * the latest conversation activity, and on mobile a single pane shows at a
 * time. The JS behaviour itself runs in the browser; these tests pin the
 * server side: the pagination contract, the feed, the ordering, the
 * attachment storage/download security and the markup hooks the JS binds to.
 */
final class MessageChatUiTest extends E2eTestCase
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

    /** Sends a message through the real composer form, one-time token included. */
    private function sendMessageFrom(AppSession $sender, int $recipientId, string $body): void
    {
        $composer = $sender->get('/?page=messages&u=' . $recipientId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders the one-time send token (#87).');
        $this->assertSame(302, $sender->post('/?page=messages&u=' . $recipientId, [
            'action' => 'send_message',
            'recipient_id' => (string)$recipientId,
            'body' => $body,
            'send_token' => $m[1],
        ])->status, 'The send redirects back to the thread.');
    }

    /** Renders the messages page after all setup and returns [status, body]. */
    private function board(AppSession $session, string $suffix = ''): array
    {
        $r = $session->get('/?page=messages' . $suffix);
        return [$r->status, $r->body];
    }

    public function test_the_thread_opens_on_the_last_20_messages_first(): void
    {
        $admin = $this->adminSession();
        $bob = $this->createRegularUser($admin, 'bobchat');
        $bobId = $this->userId('bobchat');

        // 25 messages: the initial page must show the newest 20 only.
        for ($i = 1; $i <= 25; $i++) {
            $this->sendMessageFrom($admin, $bobId, 'Chat message number ' . $i);
        }

        [$status, $body] = $this->board($admin, '&u=' . $bobId);
        $this->assertSame(200, $status);
        $this->assertTrue(str_contains($body, 'Chat message number 25'), 'The newest message renders.');
        $this->assertTrue(str_contains($body, 'Chat message number 6'), 'The 20th message from the end renders.');
        $this->assertFalse(str_contains($body, 'Chat message number 5'), 'Older messages stay behind the feed - 20 is the page size.');

        // The scroll container carries the feed cursor for the JS to follow:
        // the next older page starts below the oldest rendered message.
        $this->assertSame(1, preg_match('/data-tb-more="[^"]*&amp;before=(\d+)"/', $body, $m), 'The scroll container carries the messages_more cursor.');
        $this->assertSame('6', $m[1], 'The cursor points at the oldest rendered message.');

        // A short thread simply has no cursor to follow.
        $carl = $this->createRegularUser($admin, 'carlchat');
        $this->sendMessageFrom($admin, $this->userId('carlchat'), 'Only one.');
        [$status, $body] = $this->board($admin, '&u=' . $this->userId('carlchat'));
        $this->assertTrue(str_contains($body, 'data-tb-has-more="0"'), 'A page-size thread has nothing older to pull.');
        $this->assertTrue(str_contains($body, 'data-tb-more="?page=messages_more&amp;u=' . $this->userId('carlchat') . '&amp;before='), 'The feed URL is present.');
    }

    public function test_scrolling_up_pulls_older_pages_through_the_feed(): void
    {
        $admin = $this->adminSession();
        $dora = $this->createRegularUser($admin, 'dorachat');
        $doraId = $this->userId('dorachat');
        for ($i = 1; $i <= 23; $i++) {
            $this->sendMessageFrom($admin, $doraId, 'History message ' . $i);
        }

        // First feed page: everything older than the rendered cursor (id of
        // message 3), newest first, up to 20 per pull.
        $oldestRendered = (int)TestServer::pdo()->query('SELECT MIN(id) FROM messages')->fetchColumn() + 2;
        $feed = $admin->get('/?page=messages_more&u=' . $doraId . '&before=' . $oldestRendered);
        $this->assertSame(200, $feed->status);
        $data = json_decode((string)$feed->body, true);
        $this->assertIsArray($data, 'The feed answers with JSON.');
        $this->assertSame(['hasMore', 'oldestId', 'html'], array_keys($data), 'The feed contract is stable for the JS.');
        $this->assertFalse($data['hasMore'], '23 messages leave exactly one older page.');
        $this->assertTrue(str_contains($data['html'], 'History message 2'), 'The feed renders the older bubbles.');
        $this->assertTrue(str_contains($data['html'], 'History message 1'), 'Down to the very beginning of the conversation.');
        $this->assertFalse(str_contains($data['html'], 'History message 10'), 'The newest 20 belong to the initial page, not the feed.');
        $this->assertSame(1, $data['oldestId'], 'The feed reports its own oldest id as the next cursor.');

        // The beginning of the conversation: no cursor left to follow.
        $beginning = $admin->get('/?page=messages_more&u=' . $doraId . '&before=' . $data['oldestId']);
        $beginningData = json_decode((string)$beginning->body, true);
        $this->assertFalse($beginningData['hasMore'], 'At the beginning there is no older page left.');
        $this->assertSame('', $beginningData['html'], 'The beginning pull adds nothing.');

        // The feed is scoped to the signed-in user's own conversations: a
        // stranger's peer id yields an empty page, an unknown one a 404.
        $stranger = $this->createRegularUser($admin, 'strangerfeed');
        $strangerId = $this->userId('strangerfeed');
        $this->sendMessageFrom($admin, $strangerId, 'For the stranger only.');
        $foreign = $stranger->get('/?page=messages_more&u=' . $doraId . '&before=' . $oldestRendered);
        $this->assertSame(200, $foreign->status);
        $foreignData = json_decode((string)$foreign->body, true);
        $this->assertSame('', $foreignData['html'], 'A foreign conversation renders nothing.');
        $this->assertFalse($foreignData['hasMore'], 'A foreign conversation has no pages.');

        $missing = $admin->get('/?page=messages_more&u=99999');
        $this->assertSame(404, $missing->status, 'An unknown peer id is a 404.');
    }

    public function test_the_people_list_orders_by_latest_conversation(): void
    {
        $admin = $this->adminSession();
        $amelia = $this->createRegularUser($admin, 'amelia');
        $ameliaId = $this->userId('amelia');
        $this->createRegularUser($admin, 'bruno');
        $brunoId = $this->userId('bruno');
        $this->createRegularUser($admin, 'cecilia');
        $ceciliaId = $this->userId('cecilia');

        // Alphabetically amelia < bruno < cecilia. Amelia talks first, then
        // bruno - so bruno's conversation is the newest and must come first.
        $this->sendMessageFrom($admin, $ameliaId, 'Hi Amelia, first conversation.');
        $this->sendMessageFrom($admin, $brunoId, 'Hi Bruno, second conversation.');
        // Cecilia never chatted: she sorts after everyone, by name.

        [$status, $body] = $this->board($admin);
        $this->assertSame(200, $status);
        $brunoPos = strpos($body, 'href="?page=messages&u=' . $brunoId . '"');
        $ameliaPos = strpos($body, 'href="?page=messages&u=' . $ameliaId . '"');
        $ceciliaPos = strpos($body, 'href="?page=messages&u=' . $ceciliaId . '"');
        $this->assertNotFalse($brunoPos);
        $this->assertNotFalse($ameliaPos);
        $this->assertNotFalse($ceciliaPos);
        $this->assertTrue($brunoPos < $ameliaPos, 'The newest conversation sits at the top.');
        $this->assertTrue($ameliaPos < $ceciliaPos, 'Older conversations sit above people never written to.');

        // A new incoming message moves its conversation to the top again.
        $this->sendMessageFrom($amelia, $this->userId('admin'), 'Amelia replies - newest now.');
        [$status, $body] = $this->board($admin);
        $ameliaPos = strpos($body, 'href="?page=messages&u=' . $ameliaId . '"');
        $brunoPos = strpos($body, 'href="?page=messages&u=' . $brunoId . '"');
        $this->assertTrue($ameliaPos < $brunoPos, 'A brand-new message moves its conversation to the top.');

        // The order is stored in the messages themselves, so a user who never
        // opened the board still sees the same order on their own first visit.
        [$status, $body] = $this->board($amelia);
        $adminPos = strpos($body, 'href="?page=messages&u=' . $this->userId('admin') . '"');
        $brunoPos = strpos($body, 'href="?page=messages&u=' . $brunoId . '"');
        $this->assertTrue($adminPos !== false && $brunoPos !== false);
        $this->assertTrue($adminPos < $brunoPos, "Amelia's list mirrors the same conversation activity order.");
    }

    public function test_a_message_can_carry_an_attachment_and_partners_can_download_it(): void
    {
        $admin = $this->adminSession();
        $erna = $this->createRegularUser($admin, 'ernachat');
        $ernaId = $this->userId('ernachat');

        $composer = $admin->get('/?page=messages&u=' . $ernaId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m));
        $payload = 'Board notes for the chat attachment test.';
        $tmp = tempnam(sys_get_temp_dir(), 'tbchat') . '.txt';
        file_put_contents($tmp, $payload);
        $sent = $admin->upload('/?page=messages&u=' . $ernaId, [
            'action' => 'send_message',
            'recipient_id' => (string)$ernaId,
            'body' => 'The notes are attached.',
            'send_token' => $m[1],
        ], 'attachment', $tmp, 'board-notes.txt', 'text/plain');
        $this->assertSame(302, $sent->status, 'The send with attachment redirects like a text send.');

        // The attachment row mirrors the task/document convention.
        $row = TestServer::pdo()->query('SELECT * FROM message_attachments ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'The attachment row was stored.');
        $this->assertSame('board-notes.txt', $row['original_name']);
        $this->assertSame('text/plain', $row['mime_type']);
        $parent = TestServer::pdo()->query('SELECT sender_id, recipient_id FROM messages WHERE id=' . (int)$row['message_id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($parent, 'The attachment hangs on a real message.');
        $this->assertSame($this->userId('admin'), (int)$parent['sender_id'], 'The parent message is the admin send.');
        $this->assertSame($ernaId, (int)$parent['recipient_id'], 'The parent message is aimed at the chat partner.');

        // The bubble renders the download link; the partner downloads it.
        [$status, $body] = $this->board($admin, '&u=' . $ernaId);
        $this->assertTrue(str_contains($body, 'board-notes.txt'), 'The bubble shows the attachment name.');
        $this->assertSame(1, preg_match('/href="\?page=message_download&amp;id=(\d+)"/', $body, $dm), 'The bubble links the download route.');
        $download = $admin->get('/?page=message_download&id=' . $dm[1]);
        $this->assertSame(200, $download->status);
        $this->assertSame($payload, $download->body, 'The download serves the stored bytes.');
        $this->assertSame('attachment; filename="board-notes.txt"', (string)($download->header('content-disposition') ?? ''), 'The download carries the original name.');

        // The recipient downloads it too, a stranger gets a 403 before the
        // filesystem is touched, and an unknown id is a 404.
        $partnerDownload = $erna->get('/?page=message_download&id=' . $dm[1]);
        $this->assertSame(200, $partnerDownload->status);
        $this->assertSame($payload, $partnerDownload->body);
        $stranger = $this->createRegularUser($admin, 'strangerdown');
        $forbidden = $stranger->get('/?page=message_download&id=' . $dm[1]);
        $this->assertSame(403, $forbidden->status, 'Only the conversation partners can download.');
        $this->assertNotSame($payload, $forbidden->body, 'The forbidden download leaks no bytes.');
        $missing = $admin->get('/?page=message_download&id=999999');
        $this->assertSame(404, $missing->status);
        @unlink($tmp);
    }

    public function test_sending_rejects_disallowed_files_and_empty_sends(): void
    {
        $admin = $this->adminSession();
        $felix = $this->createRegularUser($admin, 'felixchat');
        $felixId = $this->userId('felixchat');

        // A file type outside the allowlist is rejected like on the tasks:
        // the gzip magic bytes make finfo report application/gzip, which the
        // MIME allowlist does not carry.
        $composer = $admin->get('/?page=messages&u=' . $felixId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m));
        $tmp = tempnam(sys_get_temp_dir(), 'tbchat') . '.gz';
        file_put_contents($tmp, "\x1f\x8b\x08\x00" . str_repeat('gzip payload for the rejection test', 4));
        $rejected = $admin->upload('/?page=messages&u=' . $felixId, [
            'action' => 'send_message',
            'recipient_id' => (string)$felixId,
            'body' => 'Should not pass.',
            'send_token' => $m[1],
        ], 'attachment', $tmp, 'notes-archive.gz', 'application/gzip');
        $this->assertTrue($rejected->contains('This file type is not allowed.'), 'The MIME allowlist rejects the file.');
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM messages')->fetchColumn(), 'The rejected send stored no message.');
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM message_attachments')->fetchColumn(), 'The rejected send stored no attachment.');

        // Empty body and no file: nothing to send.
        $composer = $admin->get('/?page=messages&u=' . $felixId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m2), 'A failed validation keeps the token usable (#87).');
        $empty = $admin->post('/?page=messages&u=' . $felixId, [
            'action' => 'send_message',
            'recipient_id' => (string)$felixId,
            'body' => '   ',
            'send_token' => $m2[1],
        ]);
        $this->assertTrue($empty->contains('The message cannot be empty.'), 'An empty send is rejected.');
        @unlink($tmp);
    }

    public function test_the_composer_and_chat_markup_carry_the_chat_hooks(): void
    {
        $admin = $this->adminSession();
        $gina = $this->createRegularUser($admin, 'ginachat');
        $ginaId = $this->userId('ginachat');
        $this->sendMessageFrom($admin, $ginaId, 'Hook check.');

        [$status, $body] = $this->board($admin, '&u=' . $ginaId);
        $this->assertSame(200, $status);
        // The composer: multipart form (attachment), attach button and the
        // file input the button drives, the textarea the enter-send binds to.
        $this->assertTrue(str_contains($body, 'data-tb-chat-send'), 'The send form is marked for the chat JS.');
        $this->assertTrue(str_contains($body, 'enctype="multipart/form-data"'), 'The composer accepts files.');
        $this->assertTrue(str_contains($body, 'aria-label="Attach a file"'), 'The attachment button is labelled (6).');
        $this->assertTrue(str_contains($body, 'name="attachment"'), 'The file input travels with the form.');
        $this->assertTrue(str_contains($body, 'data-tb-chat-body'), 'The textarea is marked for enter-to-send (5).');
        $this->assertTrue(str_contains($body, 'Enter sends'), 'The composer documents the send keys.');
        // The thread: full-height scroll container with the feed hooks (1-3),
        // the back button of the mobile single-pane view (10).
        $this->assertTrue(str_contains($body, 'data-tb-chat-scroll'), 'The thread is the scroll container.');
        $this->assertTrue(str_contains($body, 'data-tb-chat-scroll') && str_contains($body, 'data-tb-more='), 'The container carries the feed cursor (3).');
        $this->assertTrue(str_contains($body, 'aria-label="Back to the people list"'), 'The conversation head has the back button (10).');
        $this->assertTrue(str_contains($body, 'tb-chat-back'), 'The back button is styled.');
        $this->assertTrue(str_contains($body, 'tb-chat has-peer'), 'An explicitly opened conversation marks the mobile view (8).');
        $this->assertTrue(str_contains($body, '@media (max-width: 991.98px)'), 'The single-pane switch lives in the mobile breakpoint.');
    }

    public function test_the_mobile_view_defaults_to_the_people_list(): void
    {
        $admin = $this->adminSession();
        $hugo = $this->createRegularUser($admin, 'hugochat');
        $this->sendMessageFrom($admin, $this->userId('hugochat'), 'Mobile default check.');

        // Without an explicit ?u= the board must not mark the peer pane: the
        // mobile default stays the people list (9) even though the board
        // auto-selects a conversation on the desktop.
        [$status, $body] = $this->board($admin);
        $this->assertSame(200, $status);
        $this->assertFalse(str_contains($body, 'tb-chat has-peer'), 'No explicit conversation means no peer pane on mobile.');
        $this->assertTrue(str_contains($body, 'href="?page=messages&u=' . $this->userId('hugochat') . '"'), 'The people list renders as usual.');
    }
}
