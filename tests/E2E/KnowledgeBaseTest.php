<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #53 - the Know-hows knowledge base.
 *
 * Documents with CRUD, comments and attachments "similar to the tasks", for
 * standard processes, rule books and every other know-how. These tests pin
 * the CRUD rules, the creator-or-administrator edit/delete guard, the comment
 * and attachment round trips and the navbar entry.
 */
final class KnowledgeBaseTest extends E2eTestCase
{
    private function createDoc(AppSession $session, string $title, string $content = 'The standard process text.'): int
    {
        $response = $session->post('/?page=document_new', [
            'action' => 'doc_save',
            'title' => $title,
            'content' => $content,
        ]);
        $this->assertSame(302, $response->status, 'Saving a know-how must redirect to the list.');
        $list = $session->get('/?page=documents');
        $this->assertTrue($list->contains('page=document&id='), 'The list must link the new document.');
        preg_match('/page=document&id=(\d+)/', $list->body, $m);
        return (int)$m[1];
    }

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

    public function test_the_navbar_links_every_signed_in_user_to_the_knowledge_base(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?');
        $this->assertTrue($page->contains('href="?page=documents">Know-hows</a>'), 'The navbar must carry the Know-hows link.');
    }

    public function test_a_know_how_round_trips_create_read_update_delete(): void
    {
        $admin = $this->adminSession();
        $docId = $this->createDoc($admin, 'Server restart process', 'Step 1: announce.' . "\n" . 'Step 2: reboot.');

        $view = $admin->get('/?page=document&id=' . $docId);
        $this->assertSame(200, $view->status);
        $this->assertTrue($view->contains('Server restart process'));
        $this->assertTrue($view->contains("Step 1: announce.\nStep 2: reboot."), 'Line breaks in the content must survive (prewrap).');
        $this->assertTrue($view->contains('page=document_edit&id=' . $docId), 'The creator sees the Edit button.');
        $this->assertTrue($view->contains('doc_delete'), 'The creator sees the Delete button.');

        // Update through the prefilled edit form.
        $edit = $admin->get('/?page=document_edit&id=' . $docId);
        $this->assertSame(200, $edit->status);
        $this->assertTrue($edit->contains('value="Server restart process"'), 'The edit form must be prefilled.');
        $response = $admin->post('/?page=document_edit&id=' . $docId, [
            'action' => 'doc_save',
            'id' => (string)$docId,
            'title' => 'Server restart process v2',
            'content' => 'Updated by the #53 flow.',
        ]);
        $this->assertSame(302, $response->status);
        $view = $admin->get('/?page=document&id=' . $docId);
        $this->assertTrue($view->contains('Server restart process v2'));
        $this->assertTrue($view->contains('Updated by the #53 flow.'));
        $this->assertFalse($view->contains('Step 1: announce.'), 'The old content must be gone.');

        // Delete with confirmation; the document disappears everywhere.
        $response = $admin->post('/?page=document&id=' . $docId, ['action' => 'doc_delete', 'id' => (string)$docId]);
        $this->assertSame(302, $response->status);
        $list = $admin->get('/?page=documents');
        $this->assertFalse($list->contains('Server restart process v2'), 'The deleted document must leave the list.');
        $gone = $admin->get('/?page=document&id=' . $docId);
        $this->assertSame(404, $gone->status);
    }

    public function test_an_empty_content_document_still_renders(): void
    {
        $admin = $this->adminSession();
        $docId = $this->createDoc($admin, 'Attachment-only entry', '');
        $view = $admin->get('/?page=document&id=' . $docId);
        $this->assertTrue($view->contains('No content - see the attachments below.'), 'The view must explain an empty content body.');
    }

    public function test_edit_and_delete_stay_with_the_creator_or_an_admin(): void
    {
        $admin = $this->adminSession();
        $docId = $this->createDoc($admin, 'Admin written process');
        $alice = $this->createRegularUser($admin, 'alice');

        // The edit PAGE is gated...
        $page = $alice->get('/?page=document_edit&id=' . $docId);
        $this->assertSame(403, $page->status, 'A non-creator cannot open the edit page.');
        // ...and the edit action is guarded too. POSTed to the edit page the
        // page gate answers first (403); POSTed from anywhere else the action
        // guard answers with its own message. Two layers, one rule.
        $response = $alice->post('/?page=document_edit&id=' . $docId, [
            'action' => 'doc_save',
            'id' => (string)$docId,
            'title' => 'Hijacked',
            'content' => 'nope',
        ]);
        $this->assertSame(403, $response->status);
        $response = $alice->post('/?page=documents', [
            'action' => 'doc_save',
            'id' => (string)$docId,
            'title' => 'Hijacked',
            'content' => 'nope',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Only an administrator or the creator can edit a know-how.'));
        $response = $alice->post('/?page=document&id=' . $docId, ['action' => 'doc_delete', 'id' => (string)$docId]);
        $this->assertTrue($response->contains('Only an administrator or the creator can delete a know-how.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM documents WHERE id=' . $docId)->fetchColumn(), 'Nothing may be deleted by the guard.');

        // Alice can still create her own know-how and edit that one.
        $own = $this->createDoc($alice, 'Alice own process');
        $page = $alice->get('/?page=document_edit&id=' . $own);
        $this->assertSame(200, $page->status, 'The creator can edit their own document.');
    }

    public function test_comments_round_trip_on_a_know_how(): void
    {
        $admin = $this->adminSession();
        $docId = $this->createDoc($admin, 'Commented process');
        $alice = $this->createRegularUser($admin, 'bob');

        $response = $admin->post('/?page=document&id=' . $docId, [
            'action' => 'doc_comment',
            'document_id' => (string)$docId,
            'body' => 'Admin note on the process.',
        ]);
        $this->assertSame(302, $response->status);
        $response = $alice->post('/?page=document&id=' . $docId, [
            'action' => 'doc_comment',
            'document_id' => (string)$docId,
            'body' => 'Bob asked a question about step 2.',
        ]);
        $this->assertSame(302, $response->status);

        $view = $alice->get('/?page=document&id=' . $docId);
        $this->assertTrue($view->contains('Admin note on the process.'));
        $this->assertTrue($view->contains('Bob asked a question about step 2.'));
        $this->assertTrue($view->contains('value="doc_comment"'), 'The comment form is there for every signed-in user.');
    }

    public function test_attachments_round_trip_on_a_know_how(): void
    {
        $admin = $this->adminSession();
        $docId = $this->createDoc($admin, 'Process with attachment');
        $alice = $this->createRegularUser($admin, 'carol');

        // Alice uploads her own file to the admin's document.
        $file = tempnam(sys_get_temp_dir(), 'kb-');
        file_put_contents($file, 'know-how attachment payload 53');
        $upload = $alice->upload('/?page=document&id=' . $docId, [
            'action' => 'doc_upload',
            'document_id' => (string)$docId,
        ], 'file', $file, 'handbook.txt', 'text/plain');
        $this->assertSame(302, $upload->status);

        $view = $admin->get('/?page=document&id=' . $docId);
        $this->assertTrue($view->contains('handbook.txt'));
        preg_match('/page=doc_download&id=(\d+)/', $view->body, $m);
        $attachmentId = (int)$m[1];

        // The download delivers the exact bytes with the right headers (the
        // PHP server may append a charset to text/* types).
        $download = $admin->get('/?page=doc_download&id=' . $attachmentId);
        $this->assertSame(200, $download->status);
        $this->assertTrue(str_starts_with((string)$download->header('content-type'), 'text/plain'), 'The stored MIME must be served.');
        $this->assertSame('know-how attachment payload 53', $download->body);

        // Carol (the uploader) can delete her own attachment...
        $response = $alice->post('/?page=document&id=' . $docId, [
            'action' => 'doc_delete_attachment',
            'attachment_id' => (string)$attachmentId,
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM document_attachments WHERE id=' . $attachmentId)->fetchColumn());
        $this->assertFalse(is_file(TestServer::docRoot() . '/uploads/' . TestServer::pdo()->query('SELECT stored_name FROM document_attachments WHERE id=' . $attachmentId)->fetchColumn()), 'The stored file goes with the row.');
    }

    public function test_a_stranger_cannot_delete_someone_elses_attachment(): void
    {
        $admin = $this->adminSession();
        $docId = $this->createDoc($admin, 'Guarded attachment');
        $file = tempnam(sys_get_temp_dir(), 'kb-');
        file_put_contents($file, 'admin owned payload');
        $admin->upload('/?page=document&id=' . $docId, [
            'action' => 'doc_upload',
            'document_id' => (string)$docId,
        ], 'file', $file, 'rules.txt', 'text/plain');
        $view = $admin->get('/?page=document&id=' . $docId);
        preg_match('/page=doc_download&id=(\d+)/', $view->body, $m);
        $attachmentId = (int)$m[1];

        $alice = $this->createRegularUser($admin, 'dave');
        $response = $alice->post('/?page=document&id=' . $docId, [
            'action' => 'doc_delete_attachment',
            'attachment_id' => (string)$attachmentId,
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Only an administrator or the uploader can delete an attachment.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM document_attachments WHERE id=' . $attachmentId)->fetchColumn(), 'Nothing may be deleted by the guard.');
    }

    public function test_the_knowledge_base_search_finds_the_right_document(): void
    {
        $admin = $this->adminSession();
        $this->createDoc($admin, 'WiFi relay guide', 'How the relay talks to the nodes.');
        $this->createDoc($admin, 'Billing rules', 'How invoices are issued.');

        $page = $admin->get('/?page=documents&q=relay');
        $this->assertTrue($page->contains('WiFi relay guide'));
        $this->assertFalse($page->contains('Billing rules'), 'The search must filter the list.');

        $page = $admin->get('/?page=documents&q=invoices');
        $this->assertTrue($page->contains('Billing rules'), 'The content is searched too.');
        $this->assertFalse($page->contains('WiFi relay guide'));

        $page = $admin->get('/?page=documents&q=%25');
        $this->assertTrue($page->contains('No know-hows match the search.'), 'A literal % must not act as a wildcard.');
    }

    public function test_anonymous_users_are_pushed_to_sign_in(): void
    {
        $anonymous = new AppSession();
        foreach (['/?page=documents', '/?page=document_new', '/?page=document&id=1'] as $url) {
            $this->assertSame(302, $anonymous->get($url)->status, "{$url} must require a signed-in user.");
        }
    }

    public function test_the_uploads_orphan_sweep_keeps_document_attachments(): void
    {
        // #53 v2: the sweep used to know only the task attachment rows, so the
        // admin maintenance action deleted every knowledge-base file as an
        // "orphan". A stored file the document_attachments table references
        // must survive; genuinely stray files are still removed.
        $admin = $this->adminSession();
        $docId = $this->createDoc($admin, 'Swept know-how');

        $file = tempnam(sys_get_temp_dir(), 'kb-');
        file_put_contents($file, 'document attachment payload');
        $upload = $admin->upload('/?page=document&id=' . $docId, [
            'action' => 'doc_upload',
            'document_id' => (string)$docId,
        ], 'file', $file, 'kept-handbook.txt', 'text/plain');
        $this->assertSame(302, $upload->status);
        preg_match('/page=doc_download&id=(\d+)/', $admin->get('/?page=document&id=' . $docId)->body, $m);
        $attachmentId = (int)$m[1];
        $storedName = (string)TestServer::pdo()->query('SELECT stored_name FROM document_attachments WHERE id=' . $attachmentId)->fetchColumn();
        $keptFile = TestServer::docRoot() . '/uploads/' . $storedName;
        $this->assertFileExists($keptFile);
        $orphanFile = TestServer::docRoot() . '/uploads/orphan-doc-leftover-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($orphanFile, 'orphan payload');

        $response = $admin->post('/?page=admin', ['action' => 'cleanup_uploads']);
        $this->assertSame(302, $response->status);
        $this->assertTrue($admin->http->get('/?page=admin')->contains('orphaned attachment file(s) removed.'), 'the planted stray file was swept');

        $this->assertFileDoesNotExist($orphanFile);
        $this->assertFileExists($keptFile, 'the document attachment file must survive the sweep');

        $download = $admin->get('/?page=doc_download&id=' . $attachmentId);
        $this->assertSame(200, $download->status);
        $this->assertSame('document attachment payload', $download->body, 'the surviving file still downloads byte-identical');
    }
}
