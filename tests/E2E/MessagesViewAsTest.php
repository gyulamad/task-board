<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\HttpResponse;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #151: the administrator can view user conversations. The messages
 * page carries a "view as..." select; from then on the whole messenger -
 * the people list, the open thread, the live feed, the older-pages feed -
 * renders as the picked user would see it, with a persistent top ribbon
 * naming the viewed user and a one-click exit. Reading never consumes the
 * viewed user's state (their unread badges keep counting), sending is
 * disabled while viewing (no impersonation), and the admin's delete powers
 * work on the viewed conversation exactly "like on mine". A plain user can
 * neither enter nor keep a view-as session, and every surface falls back to
 * the pristine behaviour when nobody is being viewed.
 */
final class MessagesViewAsTest extends E2eTestCase
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

    private function enterViewAs(AppSession $admin, int $userId): HttpResponse
    {
        return $admin->post('/?page=messages', ['action' => 'view_as_enter', 'user_id' => (string)$userId]);
    }

    public function test_only_the_admin_sees_the_view_as_select(): void
    {
        $admin = $this->adminSession();
        $nina = $this->createRegularUser($admin, 'nina');

        $own = $admin->get('/?page=messages');
        $this->assertTrue($own->contains('value="view_as_enter"'), 'The admin sees the view-as select.');
        $this->assertTrue($own->contains('View as...'), 'The select renders its placeholder.');
        $this->assertTrue($own->contains('>Nina<'), 'The people of the board are pickable.');
        $this->assertFalse($own->contains('data-tb-view-as-ribbon'), 'No ribbon while viewing nobody.');

        $plain = $nina->get('/?page=messages');
        $this->assertFalse($plain->contains('value="view_as_enter"'), 'A single user sees no view-as select.');
        $this->assertFalse($plain->contains('data-tb-view-as-ribbon'), 'A single user sees no ribbon.');

        // A single user cannot enter the mode even with a hand-crafted POST.
        $ninaId = $this->userId('nina');
        $adminId = $this->userId('admin');
        $this->sendMessageFrom($admin, $ninaId, 'Nina should not read this through view-as.');
        $forced = $nina->post('/?page=messages', ['action' => 'view_as_enter', 'user_id' => (string)$adminId]);
        $this->assertTrue($forced->contains('Only an administrator can view the board as another user.'), 'The enter action rejects a single user.');
        $still = $nina->get('/?page=messages&u=' . $adminId);
        $this->assertTrue($still->contains('Nina should not read this through view-as.'), 'The admin-to-nina thread stays reachable only for its participants.');
        $other = $nina->post('/?page=messages&u=0', ['action' => 'view_as_enter', 'user_id' => '0']);
        $this->assertTrue($other->contains('Only an administrator can view the board as another user.'));
    }

    public function test_view_as_renders_the_picked_user_messenger(): void
    {
        $admin = $this->adminSession();
        $ola = $this->createRegularUser($admin, 'ola');
        $olaId = $this->userId('ola');
        $pia = $this->createRegularUser($admin, 'pia');
        $piaId = $this->userId('pia');

        // Ola and Pia talk; the admin is not part of the conversation.
        $this->sendMessageFrom($pia, $olaId, 'Pia to Ola, visible through view-as.');
        $this->sendMessageFrom($ola, $piaId, 'Ola replying to Pia.');

        // The admin's own messenger shows neither thread.
        $own = $admin->get('/?page=messages');
        $this->assertFalse($own->contains('Pia to Ola, visible through view-as.'), 'The admin does not see foreign messages on their own view.');

        // Enter view-as Ola: the ribbon names Ola, the select marks Ola, and
        // the open conversation is the picked user's.
        $this->assertSame(302, $this->enterViewAs($admin, $olaId)->status);
        $asOla = $admin->get('/?page=messages&u=' . $piaId);
        $this->assertTrue($asOla->contains('data-tb-view-as-ribbon'), 'The ribbon renders while viewing.');
        $this->assertTrue($asOla->contains('Messages are viewed as <b>Ola</b>'), 'The ribbon names the viewed user.');
        $this->assertTrue($asOla->contains('value="view_as_exit"'), 'The ribbon carries the one-click exit.');
        $this->assertTrue($asOla->contains('Pia to Ola, visible through view-as.'), 'The viewed user\'s thread renders.');
        $this->assertTrue($asOla->contains('Ola replying to Pia.'), 'Both halves of the foreign conversation render.');
        $this->assertTrue($asOla->contains('Viewing as Ola - sending is disabled'), 'The composer is replaced by the read-only note.');
        $this->assertFalse($asOla->contains('data-tb-chat-send'), 'No send form while viewing.');
        $this->assertTrue($asOla->contains('value="delete_conversation"'), 'The delete controls stay available in view-as.');

        // The bubble sides follow the VIEWED user: Ola's own message renders
        // on her side of the thread (the justify-content-end container with
        // the data-tb-msg-status seen hook, which only "mine" bubbles carry),
        // Pia's renders as the peer's.
        $this->assertTrue($asOla->contains('justify-content-end'), 'The viewed user\'s own bubbles render on their side.');
        $this->assertTrue($asOla->contains('justify-content-start'), 'The peer bubbles render on the other side.');
        $this->assertTrue($asOla->contains('data-tb-msg-status'), 'The seen-status hook renders on the viewed user\'s own bubbles.');

        // The live feed and the older-pages feed run as the viewed user too.
        $feed = json_decode((string)$admin->get('/?page=messages_live&u=' . $piaId . '&after=0')->body, true);
        $this->assertStringContainsString('Pia to Ola, visible through view-as.', (string)$feed['html'], 'The live feed serves the viewed user\'s thread.');
        $more = json_decode((string)$admin->get('/?page=messages_more&u=' . $piaId . '&before=' . ($feed['lastId'] + 1))->body, true);
        $this->assertIsArray($more, 'The older-pages feed answers.');
    }

    public function test_view_as_never_consumes_the_viewed_user_state(): void
    {
        $admin = $this->adminSession();
        $ruth = $this->createRegularUser($admin, 'ruth');
        $ruthId = $this->userId('ruth');
        $sven = $this->createRegularUser($admin, 'sven');
        $svenId = $this->userId('sven');

        // Sven writes to Ruth: one unread message on Ruth's side.
        $this->sendMessageFrom($sven, $ruthId, 'Unread for Ruth.');
        $unread = function () use ($svenId): int {
            $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id=' . $this->userId('ruth') . ' AND sender_id=' . $svenId . ' AND is_read=0');
            $s->execute();
            return (int)$s->fetchColumn();
        };
        $this->assertSame(1, $unread(), 'The message starts unread for Ruth.');

        // The admin reads the whole conversation as Ruth: the page and the
        // live poll both. Ruth's badge must survive every one of them.
        $this->enterViewAs($admin, $ruthId);
        $admin->get('/?page=messages&u=' . $svenId);
        $admin->get('/?page=messages_live&u=' . $svenId . '&after=0');
        $this->assertSame(1, $unread(), 'Viewing as Ruth did not mark her message read.');

        // The exit restores the consuming behaviour: the admin opening THEIR
        // own view marks their own messages read, as it always did.
        $admin->post('/?page=messages', ['action' => 'view_as_exit']);
        $admin->get('/?page=messages');
        $this->assertSame(1, $unread(), 'The admin\'s own page load still never touches Ruth\'s badges.');
    }

    public function test_sending_is_refused_while_viewing(): void
    {
        $admin = $this->adminSession();
        $tara = $this->createRegularUser($admin, 'tara');
        $taraId = $this->userId('tara');
        $ull = $this->createRegularUser($admin, 'ull');
        $ullId = $this->userId('ull');
        $this->sendMessageFrom($tara, $ullId, 'Thread filler.');

        $this->enterViewAs($admin, $taraId);
        $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM messages');
        $s->execute();
        $before = (int)$s->fetchColumn();

        // A hand-crafted send under the viewed user's context is refused: no
        // message row, a clean validation error.
        $refused = $admin->post('/?page=messages&u=' . $ullId, [
            'action' => 'send_message',
            'recipient_id' => (string)$ullId,
            'body' => 'Impersonation attempt.',
            'send_token' => 'whatever',
        ]);
        $this->assertTrue($refused->contains('Sending is disabled while viewing the board as another user.'), 'The send action refuses in view-as.');
        $s->execute();
        $this->assertSame($before, (int)$s->fetchColumn(), 'Nothing was written.');

        // The normal send still works once the viewing ended.
        $admin->post('/?page=messages', ['action' => 'view_as_exit']);
        $composer = $admin->get('/?page=messages&u=' . $taraId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders again after the exit.');
        $sent = $admin->post('/?page=messages&u=' . $taraId, [
            'action' => 'send_message',
            'recipient_id' => (string)$taraId,
            'body' => 'Back under my own name.',
            'send_token' => $m[1],
        ]);
        $this->assertSame(302, $sent->status, 'The admin\'s own send goes through.');
    }

    public function test_the_admin_deletes_a_viewed_conversation_like_their_own(): void
    {
        $admin = $this->adminSession();
        $vera = $this->createRegularUser($admin, 'vera');
        $veraId = $this->userId('vera');
        $wanda = $this->createRegularUser($admin, 'wanda');
        $wandaId = $this->userId('wanda');
        $xaver = $this->createRegularUser($admin, 'xaver');
        $xaverId = $this->userId('xaver');

        // Two foreign conversations: Vera<->Wanda (the one to wipe) and
        // Vera<->Xaver (the one to keep). The admin is in neither.
        $this->sendMessageFrom($wanda, $veraId, 'Wanda to Vera, doomed.');
        $this->sendMessageFrom($vera, $wandaId, 'Vera replying, doomed.');
        $this->sendMessageFrom($xaver, $veraId, 'Xaver to Vera, kept.');

        $this->enterViewAs($admin, $veraId);

        // The single-message delete lands back in the viewed thread.
        $thread = $admin->get('/?page=messages&u=' . $wandaId);
        $this->assertSame(1, preg_match('/name="action" value="delete_message"><input type="hidden" name="message_id" value="(\d+)"/', $thread->body, $m), 'The viewed conversation renders the per-message controls.');
        $one = $admin->post('/?page=messages&u=' . $wandaId, ['action' => 'delete_message', 'message_id' => $m[1]]);
        $this->assertSame(302, $one->status);
        $this->assertSame('?page=messages&u=' . $wandaId, $one->header('location'), 'The delete lands back in the viewed conversation.');

        // The whole-conversation delete wipes the viewed pair only.
        $whole = $admin->post('/?page=messages&u=' . $wandaId, ['action' => 'delete_conversation', 'u' => (string)$wandaId]);
        $this->assertSame(302, $whole->status);
        $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)');
        $s->execute([$veraId, $wandaId, $wandaId, $veraId]);
        $this->assertSame(0, (int)$s->fetchColumn(), 'The Vera-Wanda conversation is gone.');
        $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)');
        $s->execute([$veraId, $xaverId, $xaverId, $veraId]);
        $this->assertSame(1, (int)$s->fetchColumn(), 'The Vera-Xaver conversation is untouched.');
        $this->assertTrue($admin->get('/?page=messages&u=' . $xaverId)->contains('Xaver to Vera, kept.'), 'The kept thread still renders through the view-as.');

        // The bulk delete works on the viewed thread too.
        $this->sendMessageFrom($xaver, $veraId, 'Bulk target.');
        $this->sendMessageFrom($xaver, $veraId, 'Bulk survivor.');
        $s = TestServer::pdo()->prepare('SELECT id FROM messages WHERE body=?');
        $s->execute(['Bulk target.']);
        $bulkId = (int)$s->fetchColumn();
        $bulk = $admin->post('/?page=messages&u=' . $xaverId, ['action' => 'delete_messages', 'u' => (string)$xaverId, 'ids' => [(string)$bulkId]]);
        $this->assertSame(302, $bulk->status);
        $this->assertFalse($admin->get('/?page=messages&u=' . $xaverId)->contains('Bulk target.'), 'The bulk-selected message is gone.');
        $this->assertTrue($admin->get('/?page=messages&u=' . $xaverId)->contains('Bulk survivor.'), 'The rest of the thread stays.');

        // A single user still cannot delete anything, view-as or not.
        $denied = $vera->post('/?page=messages&u=' . $wandaId, ['action' => 'delete_conversation', 'u' => (string)$wandaId]);
        $this->assertTrue($denied->contains('Only an administrator can delete messages.'), 'The guard holds for single users.');
    }

    public function test_the_exit_and_a_picked_self_restore_the_own_view(): void
    {
        $admin = $this->adminSession();
        $yuri = $this->createRegularUser($admin, 'yuri');
        $yuriId = $this->userId('yuri');
        $this->sendMessageFrom($yuri, $this->userId('admin'), 'Yuri to the admin.');

        $this->enterViewAs($admin, $yuriId);
        $this->assertTrue($admin->get('/?page=messages')->contains('data-tb-view-as-ribbon'), 'Viewing is on.');

        // The ribbon's exit click ends the session.
        $exit = $admin->post('/?page=messages', ['action' => 'view_as_exit']);
        $this->assertSame(302, $exit->status);
        $own = $admin->get('/?page=messages');
        $this->assertFalse($own->contains('data-tb-view-as-ribbon'), 'The ribbon is gone after the exit.');
        $this->assertTrue($own->contains('Back to your own messages.'), 'The exit confirms itself.');
        $this->assertTrue($own->contains('Yuri to the admin.'), 'The admin sees their own thread again.');
        $this->assertTrue($own->contains('data-tb-chat-send'), 'The composer is back.');

        // Entering with the admin's own id is a clean no-op exit.
        $this->enterViewAs($admin, $yuriId);
        $self = $admin->post('/?page=messages', ['action' => 'view_as_enter', 'user_id' => (string)$this->userId('admin')]);
        $this->assertSame(302, $self->status);
        $this->assertFalse($admin->get('/?page=messages')->contains('data-tb-view-as-ribbon'), 'Picking yourself means viewing nobody.');

        // An unknown user id cannot smuggle a session in either.
        $unknown = $admin->post('/?page=messages', ['action' => 'view_as_enter', 'user_id' => '999999']);
        $this->assertSame(302, $unknown->status);
        $this->assertFalse($admin->get('/?page=messages')->contains('data-tb-view-as-ribbon'), 'An unknown id never starts a viewing session.');
    }

    public function test_a_deleted_viewed_user_falls_back_to_the_own_view(): void
    {
        $admin = $this->adminSession();
        $zora = $this->createRegularUser($admin, 'zora');
        $zoraId = $this->userId('zora');
        $this->sendMessageFrom($zora, $this->userId('admin'), 'Zora says hi.');

        $this->enterViewAs($admin, $zoraId);
        $this->assertTrue($admin->get('/?page=messages')->contains('data-tb-view-as-ribbon'), 'Viewing Zora is on.');

        // The viewed account is deleted mid-view: the next messenger request
        // falls back to the admin's own view (no ribbon, no crash), the
        // stale session entry clears itself, and the orphan thread (#17)
        // keeps the messages reachable.
        $admin->post('/?page=users', ['action' => 'delete_user', 'id' => (string)$zoraId]);
        $fallback = $admin->get('/?page=messages');
        $this->assertFalse($fallback->contains('data-tb-view-as-ribbon'), 'The stale view-as session does not render.');
        $this->assertTrue($fallback->contains('data-tb-chat-send'), 'The admin\'s own messenger works.');
        $this->assertTrue($admin->get('/?page=messages&u=0')->contains('Zora says hi.'), 'The orphan thread keeps the conversation.');
    }

    public function test_view_as_admin_can_download_a_viewed_conversation_attachment(): void
    {
        $admin = $this->adminSession();
        $adam = $this->createRegularUser($admin, 'adam');
        $adamId = $this->userId('adam');
        $bela = $this->createRegularUser($admin, 'bela');
        $belaId = $this->userId('bela');

        // Bela sends Adam an attachment: the participants are Adam and Bela.
        $composer = $bela->get('/?page=messages&u=' . $adamId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m));
        $tmp = tempnam(sys_get_temp_dir(), 'tbview') . '.txt';
        file_put_contents($tmp, 'attachment payload for the view-as download test');
        $sent = $bela->upload('/?page=messages&u=' . $adamId, [
            'action' => 'send_message',
            'recipient_id' => (string)$adamId,
            'body' => 'With a paperclip.',
            'send_token' => $m[1],
        ], 'attachment', $tmp, 'view-as-notes.txt', 'text/plain');
        $this->assertSame(302, $sent->status, 'The attachment send goes through.');
        $attachId = (int)TestServer::pdo()->query('SELECT id FROM message_attachments ORDER BY id DESC LIMIT 1')->fetchColumn();

        // A stranger (a third user) is 403, as before.
        $csaba = $this->createRegularUser($admin, 'csaba');
        $this->assertSame(403, $csaba->get('/?page=message_download&id=' . $attachId)->status, 'A stranger stays locked out.');

        // The admin in view-as Adam is a participant of Adam's conversations:
        // the paperclip opens. Without the viewing session the admin (not a
        // participant) stays 403, exactly like before #151.
        $this->assertSame(403, $admin->get('/?page=message_download&id=' . $attachId)->status, 'The admin alone is not a participant.');
        $this->enterViewAs($admin, $adamId);
        $download = $admin->get('/?page=message_download&id=' . $attachId);
        $this->assertSame(200, $download->status, 'View-as makes the admin a participant of the viewed conversation.');
        $this->assertStringContainsString('attachment payload for the view-as download test', $download->body, 'The payload arrives.');
        @unlink($tmp);
    }
}
