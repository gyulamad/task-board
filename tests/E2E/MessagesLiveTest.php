<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\HttpResponse;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #114: the messenger page behaves like a chat app between full loads.
 * A JSON poll feed (messages_live) hands the open page the bubbles newer
 * than its cursor, the people rows exactly as a fresh load would render
 * them (unread badges, activity indicators, open-conversation highlight)
 * and the viewer's unread count for the page badge. Viewing semantics stay
 * the board's own: only the bubbles the feed renders - the open
 * conversation's - flip to read; a message from anybody else keeps its red
 * badge until their conversation is opened.
 */
final class MessagesLiveTest extends E2eTestCase
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

    public function test_the_intro_hides_on_mobile_and_the_chat_takes_the_top(): void
    {
        // Task #117: on a phone the page intro (title + description) only
        // pushed the chat down - below the lg breakpoint it is hidden, so the
        // chat top sits right below the main menu header. The markup stays in
        // place (the newcount badge hook included) and only the stylesheet
        // hides it; the chat re-size JS measures the freed space at runtime.
        $admin = $this->adminSession();
        $body = $admin->get('/?page=messages')->body;
        $this->assertTrue(str_contains($body, 'd-flex justify-content-between align-items-center mb-3 tb-chat-intro'), 'The intro row is marked for the mobile hide.');
        $this->assertTrue(str_contains($body, '.tb-chat-intro{display:none!important}'), 'The mobile media query hides the intro - with !important, because the intro row carries the d-flex utility whose display:flex!important beats a plain display:none (#117 v2).');
        $this->assertTrue(str_contains($body, 'data-tb-chat-newcount'), 'The newcount badge hook survives in the markup.');
    }

    public function test_the_message_sent_flash_hides_on_mobile(): void
    {
        // Task #129: after a send the green "Message sent." ribbon renders on
        // the top of the chat, is never dismissed, and on a phone it only
        // eats chat area - the bubble itself carries the live Sent/Seen
        // status (#118), so the ribbon is redundant there. The send
        // confirmation is marked in the markup and the mobile media query
        // hides exactly it: desktop keeps the ribbon (the markup renders
        // unchanged) and every other flash still renders on the phone.
        $admin = $this->adminSession();
        $peer = $this->createRegularUser($admin, 'flashmobile');
        $peerId = $this->userId('flashmobile');
        $this->sendMessageFrom($admin, $peerId, 'Watch the ribbon, not the phone.');

        $body = $admin->get('/?page=messages&u=' . $peerId)->body;
        $this->assertTrue(str_contains($body, '<div class="alert alert-success tb-flash-message-sent">Message sent.</div>'), 'The send confirmation renders marked for the mobile hide - the same markup serves the desktop, which keeps the ribbon.');

        // The hide rule lives inside the mobile media query - the ribbon is
        // only hidden below the lg breakpoint, exactly like the #117 intro.
        $this->assertSame(1, preg_match('/@media \(max-width: 991\.98px\)\{.*\.tb-flash-message-sent\{display:none!important\}/s', $body), 'The mobile media query hides the marked ribbon.');
        $this->assertSame(1, substr_count($body, '.tb-flash-message-sent{display:none!important}'), 'The hide rule exists exactly once, inside the mobile media query - it must not leak above the lg breakpoint.');

        // Any other flash stays visible on the phone: a replayed send token
        // (#87) renders the dropped-submit note WITHOUT the mobile-hide mark
        // - hiding it would silence the only feedback a double submit gives.
        $composer = $admin->get('/?page=messages&u=' . $peerId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders the one-time send token (#87).');
        $fields = ['action' => 'send_message', 'recipient_id' => (string)$peerId, 'body' => 'Double click.', 'send_token' => $m[1]];
        $admin->post('/?page=messages&u=' . $peerId, $fields);
        $admin->post('/?page=messages&u=' . $peerId, $fields);
        $dropped = $admin->get('/?page=messages&u=' . $peerId)->body;
        $this->assertTrue(str_contains($dropped, '<div class="alert alert-success">This submit was dropped'), 'The dropped-submit note renders unmarked.');
        // The class name appears exactly once on the page - inside the CSS
        // rule itself - so no flash div on the dropped page carries the mark.
        $this->assertSame(1, substr_count($dropped, 'tb-flash-message-sent'), 'No other flash carries the mobile-hide mark.');
    }

    public function test_the_bubbles_carry_live_hooks_and_the_feed_reports_seen_progress(): void
    {
        // Task #118: the sending time and the seen status used to freeze at
        // page load - only a full reload refreshed them. Now the bubbles
        // carry the epoch hook for the in-place suffix refresh, and the feed
        // reports how far the peer has read, so the Sent labels flip without
        // a reload.
        $admin = $this->adminSession();
        $peer = $this->createRegularUser($admin, 'livehookpeer');
        $peerId = $this->userId('livehookpeer');
        $this->sendMessageFrom($admin, $peerId, 'Watch this bubble age.');

        $body = $admin->get('/?page=messages&u=' . $peerId)->body;
        $this->assertTrue(str_contains($body, 'data-tb-ago-sfx data-tb-ago-ts="'), 'The bubble suffix carries its epoch for the in-place refresh.');
        $this->assertTrue(str_contains($body, '>just now</span>'), 'A fresh message renders the just-now suffix.');
        $this->assertTrue(str_contains($body, 'data-tb-msg-status>Sent</span>'), 'An unread own message starts as Sent.');
        $this->assertTrue(str_contains($body, 'data-tb-msg-id="'), 'The bubbles carry their message id.');

        $before = json_decode((string)$admin->get('/?page=messages_live&u=' . $peerId . '&after=0')->body, true);
        $this->assertSame(0, $before['seenUpTo'], 'Nobody has read the thread yet.');

        // The peer opening the conversation is what marks it read (#114);
        // the next admin poll reports how far the seen status reached.
        $this->assertSame(200, $peer->get('/?page=messages&u=' . $this->userId('admin'))->status);

        $after = json_decode((string)$admin->get('/?page=messages_live&u=' . $peerId . '&after=0')->body, true);
        $this->assertGreaterThan(0, $after['seenUpTo'], 'The seen status must travel through the feed.');
        $this->assertTrue(str_contains((string)$admin->get('/?page=messages&u=' . $peerId)->body, 'data-tb-msg-status>Seen</span>'), 'A fresh load renders the read message as Seen.');
    }

    public function test_the_ago_suffix_stays_in_single_parentheses(): void
    {
        // Task #125 bug 1: the suffix span renders inside the PHP markup's
        // own parentheses, so the in-place JS refresh must write back the
        // bare grammar - a re-wrapped pair doubled it into ((4 minutes ago)).
        $admin = $this->adminSession();
        $peer = $this->createRegularUser($admin, 'singleparens');
        $peerId = $this->userId('singleparens');
        $this->sendMessageFrom($admin, $peerId, 'Time it right.');

        $body = $admin->get('/?page=messages&u=' . $peerId)->body;
        $this->assertTrue(str_contains($body, '(<span data-tb-ago-sfx data-tb-ago-ts="'), 'The PHP renders exactly one paren before the suffix span.');
        $this->assertTrue(str_contains($body, '>just now</span>)'), 'And exactly one paren after it.');
        $this->assertFalse(str_contains($body, '((<span data-tb-ago-sfx'), 'No doubled opening paren on a fresh load.');

        // The refresh script writes back the bare suffix (the parens belong
        // to the markup), so a poll can never double them either.
        $this->assertTrue(str_contains($body, 'els[i].textContent=sfx;'), 'The JS refresh writes the bare suffix (#125).');
        $this->assertFalse(str_contains($body, "'('+sfx+')'"), 'The JS refresh must not re-wrap the suffix in parentheses (#125).');

        // The feed appends the very same markup shape.
        $feed = json_decode((string)$admin->get('/?page=messages_live&u=' . $peerId . '&after=0')->body, true);
        $this->assertStringNotContainsString('((<span data-tb-ago-sfx', (string)$feed['html'], 'The feed bubbles carry the single-paren shape too.');
    }

    public function test_the_composer_focus_survives_the_enter_send(): void
    {
        // Task #125 bug 2: Enter sends through a full form submit and the
        // page reloads - the composer must come back focused, so the next
        // message can be typed without clicking back into the field.
        $admin = $this->adminSession();
        $peer = $this->createRegularUser($admin, 'stayfocused');
        $peerId = $this->userId('stayfocused');

        $before = $admin->get('/?page=messages&u=' . $peerId);
        $this->assertTrue(str_contains($before->body, 'data-tb-chat-body autofocus>'), 'The composer textarea declares autofocus.');

        $this->assertSame(302, $this->sendMessageFrom($admin, $peerId, 'Typed without a click.')->status, 'Enter send still goes through the full submit.');

        // The page the browser lands on after the send carries the composer,
        // focused again - the cursor never really leaves the input area.
        $after = $admin->get('/?page=messages&u=' . $peerId);
        $this->assertTrue(str_contains($after->body, 'data-tb-chat-body autofocus>'), 'The reloaded thread focuses the composer again.');
        $this->assertTrue(str_contains($after->body, 'Typed without a click.'));
    }

    public function test_the_page_carries_the_live_hooks(): void
    {
        $admin = $this->adminSession();
        $peer = $this->createRegularUser($admin, 'hookpeer');
        $peerId = $this->userId('hookpeer');

        $body = $admin->get('/?page=messages&u=' . $peerId)->body;
        $this->assertTrue(str_contains($body, 'data-tb-chat-live-url="?page=messages_live&amp;u=' . $peerId . '"'), 'The chat root carries the live feed URL (the retired cp parameter is gone).');
        $this->assertTrue(str_contains($body, 'data-tb-chat-people-rows'), 'The people rows sit in their own swap region.');
        $this->assertTrue(str_contains($body, 'data-tb-chat-live-people="1"'), 'The region opts in to live swaps.');
        $this->assertTrue(str_contains($body, 'data-tb-after="0"'), 'An empty thread starts the live cursor at zero.');
        $this->assertTrue(str_contains($body, 'data-tb-chat-newcount'), 'The page badge is marked for live count updates.');

        // With a people search active the rows region opts out: the visitor
        // is mid-search and must not have their filtered list swapped away.
        $searched = $admin->get('/?page=messages&u=' . $peerId . '&q=Hook');
        $this->assertTrue(str_contains($searched->body, 'data-tb-chat-people-rows'));
        $this->assertFalse(str_contains($searched->body, 'data-tb-chat-live-people="1"'), 'No live people swap while a people search is active.');
    }

    public function test_the_feed_returns_new_bubbles_and_marks_the_open_conversation_read(): void
    {
        $admin = $this->adminSession();
        $peer = $this->createRegularUser($admin, 'livepeer');
        $peerId = $this->userId('livepeer');
        $other = $this->createRegularUser($admin, 'liveother');
        $otherId = $this->userId('liveother');

        // The viewer opens the conversation: the baseline is empty, cursor 0.
        $this->sendMessageFrom($peer, $this->userId('admin'), 'Peer says hi.');
        $board = $admin->get('/?page=messages&u=' . $peerId);
        $this->assertSame(1, preg_match('/data-tb-after="(\d+)"/', $board->body, $m), 'The page carries the live cursor.');
        $cursor = (int)$m[1];
        $this->assertGreaterThan(0, $cursor, 'The cursor sits on the rendered bubble.');

        // A new message arrives after the page was rendered.
        $this->sendMessageFrom($peer, $this->userId('admin'), 'Live follow-up question.');

        $feed = $admin->get('/?page=messages_live&u=' . $peerId . '&cp=messages&after=' . $cursor);
        $this->assertSame(200, $feed->status);
        $data = json_decode((string)$feed->body, true);
        $this->assertIsArray($data, 'The feed answers with JSON.');
        $this->assertSame(['html', 'lastId', 'peopleHtml', 'newCount', 'seenUpTo'], array_keys($data), 'The feed contract is stable for the JS (seenUpTo is the #118 seen-progress key).');
        $this->assertTrue(str_contains($data['html'], 'Live follow-up question.'), 'The feed carries the new bubble.');
        $this->assertTrue(str_contains($data['html'], 'justify-content-start'), 'A foreign bubble sits on the left.');
        $newId = (int)TestServer::pdo()->query("SELECT MAX(id) FROM messages WHERE body='Live follow-up question.'")->fetchColumn();
        $this->assertSame($newId, $data['lastId'], 'The feed advances the cursor to the newest bubble.');

        // The open conversation's incoming message was just on screen: it is
        // read now, so the peer's badge is gone from the swapped rows.
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM messages WHERE recipient_id=' . $this->userId('admin') . " AND body='Live follow-up question.' AND is_read=0")->fetchColumn(), 'The open conversation is marked read by the feed render.');
        $this->assertFalse(str_contains($data['peopleHtml'], 'Livepeer</span><span class="badge text-bg-danger">'), 'The swapped rows show no badge for the open peer.');

        // A message from somebody else keeps its unread badge - the viewer is
        // not in that conversation.
        $this->sendMessageFrom($other, $this->userId('admin'), 'Unrelated ping.');
        $feed2 = $admin->get('/?page=messages_live&u=' . $peerId . '&cp=messages&after=' . $data['lastId']);
        $data2 = json_decode((string)$feed2->body, true);
        $this->assertSame('', $data2['html'], 'The open thread gained nothing.');
        $this->assertSame(1, $data2['newCount'], 'The page badge counts the foreign message.');
        $this->assertTrue(str_contains($data2['peopleHtml'], 'title="New messages"') || str_contains($data2['peopleHtml'], 'badge text-bg-danger">1</span>'), 'The swapped rows carry the foreign badge.');
    }

    public function test_an_empty_poll_returns_an_empty_append_and_keeps_the_cursor(): void
    {
        $admin = $this->adminSession();
        $peer = $this->createRegularUser($admin, 'quietpeer');
        $peerId = $this->userId('quietpeer');
        $this->sendMessageFrom($peer, $this->userId('admin'), 'Baseline only.');
        $board = $admin->get('/?page=messages&u=' . $peerId);
        $this->assertSame(1, preg_match('/data-tb-after="(\d+)"/', $board->body, $m));
        $cursor = (int)$m[1];

        $feed = $admin->get('/?page=messages_live&u=' . $peerId . '&after=' . $cursor);
        $data = json_decode((string)$feed->body, true);
        $this->assertSame('', $data['html'], 'Nothing new, nothing to append.');
        $this->assertSame($cursor, $data['lastId'], 'The cursor stays.');
        $this->assertSame(0, $data['newCount'], 'Nothing new anywhere.');
    }

    public function test_the_feed_validates_the_conversation_like_the_page(): void
    {
        $admin = $this->adminSession();
        $missing = $admin->get('/?page=messages_live&u=99999&after=0');
        $this->assertSame(404, $missing->status, 'An unknown peer id is a 404.');

        $negative = $admin->get('/?page=messages_live&u=-5&after=0');
        $this->assertSame(404, $negative->status, 'A crafted peer id is a 404.');

        // A stranger's conversation yields no bubbles: the pair WHERE clause
        // scopes every row to the viewer.
        $peer = $this->createRegularUser($admin, 'fencepeer');
        $other = $this->createRegularUser($admin, 'fenceother');
        $this->sendMessageFrom($peer, $this->userId('fenceother'), 'Between the two of us.');
        $foreign = $admin->get('/?page=messages_live&u=' . $this->userId('fencepeer') . '&after=0');
        $data = json_decode((string)$foreign->body, true);
        $this->assertSame('', $data['html'], 'A foreign conversation renders nothing.');

        // The rows link the messenger's canonical page name; the retired
        // cp parameter (#57's closing rename) is ignored - a crafted value
        // cannot steer the links anywhere.
        $v2 = $admin->get('/?page=messages_live&u=' . $this->userId('fencepeer') . '&cp=messages-v2&after=0');
        $dataV2 = json_decode((string)$v2->body, true);
        $this->assertTrue(str_contains($dataV2['peopleHtml'], '?page=messages&u='), 'The rows link the canonical messenger page.');
    }

    public function test_the_feed_requires_a_signed_in_viewer(): void
    {
        $response = $this->session->get('/?page=messages_live&u=1&after=0');
        $this->assertSame(302, $response->status, 'An anonymous poll is redirected to the sign-in page.');
        $this->assertSame('?page=login', (string)$response->header('location'));
    }
}
