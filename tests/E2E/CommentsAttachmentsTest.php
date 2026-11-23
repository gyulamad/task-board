<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

final class CommentsAttachmentsTest extends E2eTestCase
{
    public function test_comments_can_be_added_and_are_shown_with_history(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Commented task');

        $response = $admin->post('/?page=task&id=' . $id, [
            'action' => 'comment',
            'task_id' => (string)$id,
            'body' => 'First comment body',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('#comments', (string)$response->header('location'));

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('First comment body'));
        $this->assertTrue($page->contains('Administrator'));
        $this->assertTrue($page->contains('Comment added'));

        $comments = TestServer::pdo()->query('SELECT COUNT(*) FROM comments WHERE task_id=' . $id)->fetchColumn();
        $this->assertSame('1', (string)$comments);
    }

    public function test_empty_comments_are_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Quiet task');

        $response = $admin->post('/?page=task&id=' . $id, [
            'action' => 'comment',
            'task_id' => (string)$id,
            'body' => '   ',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Comment cannot be empty.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM comments')->fetchColumn());
    }

    public function test_comments_on_missing_tasks_are_rejected(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=task&id=4242', [
            'action' => 'comment',
            'task_id' => '4242',
            'body' => 'ghost comment',
        ]);
        // The task page itself 404s, so the comment can never be stored.
        $this->assertSame(404, $response->status);
        $this->assertTrue($response->contains('Task not found.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM comments')->fetchColumn());
    }

    public function test_text_attachment_round_trip(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Attachment task');
        $file = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($file, 'attachment payload 123');

        $response = $admin->upload('/?page=task&id=' . $id, [
            'action' => 'upload',
            'task_id' => (string)$id,
        ], 'file', $file, 'report.txt', 'text/plain');
        $this->assertSame(302, $response->status);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('report.txt'));
        $this->assertTrue($page->contains('Attachment added'));

        $download = $admin->get('/?page=download&id=1');
        $this->assertSame(200, $download->status);
        $this->assertStringContainsString('text/plain', (string)$download->header('content-type'));
        $this->assertSame('attachment payload 123', $download->body);

        unlink($file);
    }

    public function test_png_attachment_round_trip(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Image task');
        // 1x1 transparent PNG
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $file = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($file, $png);

        $response = $admin->upload('/?page=task&id=' . $id, [
            'action' => 'upload',
            'task_id' => (string)$id,
        ], 'file', $file, 'pixel.png', 'image/png');
        $this->assertSame(302, $response->status);

        $download = $admin->get('/?page=download&id=1');
        $this->assertSame(200, $download->status);
        $this->assertStringContainsString('image/png', (string)$download->header('content-type'));
        $this->assertSame($png, $download->body);

        unlink($file);
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Upload guard task');
        $file = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($file, '<?php echo "pwned";');

        $response = $admin->upload('/?page=task&id=' . $id, [
            'action' => 'upload',
            'task_id' => (string)$id,
        ], 'file', $file, 'shell.php', 'application/x-php');
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('This file type is not allowed.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM attachments')->fetchColumn());

        unlink($file);
    }

    public function test_oversized_files_are_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Size limit task');
        $file = tempnam(sys_get_temp_dir(), 'upload-');
        $fh = fopen($file, 'w');
        fseek($fh, 5 * 1024 * 1024 + 100);
        fwrite($fh, 'x');
        fclose($fh);

        $response = $admin->upload('/?page=task&id=' . $id, [
            'action' => 'upload',
            'task_id' => (string)$id,
        ], 'file', $file, 'huge.txt', 'text/plain');
        // PHP itself refuses uploads beyond upload_max_filesize before the app
        // sees them, so the app reports an empty upload gracefully instead.
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Choose a file.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM attachments')->fetchColumn());

        unlink($file);
    }

    public function test_downloads_become_unavailable_after_task_deletion(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Cascade task');
        $file = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($file, 'cascade payload');

        $admin->upload('/?page=task&id=' . $id, [
            'action' => 'upload',
            'task_id' => (string)$id,
        ], 'file', $file, 'cascaded.txt', 'text/plain');
        unlink($file);

        // Deleting goes through the archive (#12): archive first, then remove permanently.
        $admin->post('/?page=task&id=' . $id, ['action' => 'archive_task', 'id' => (string)$id]);
        $admin->post('/?page=task&id=' . $id, ['action' => 'delete_task', 'id' => (string)$id, 'return' => '?page=archive']);

        $download = $admin->get('/?page=download&id=1');
        $this->assertSame(404, $download->status);
        $this->assertTrue($download->contains('File not found.'));
    }

    public function test_attachment_can_be_deleted_by_the_uploader(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Attachment delete task');
        $attachmentId = $this->uploadTextFile($admin, $id, 'deleteme.txt', 'delete me payload');
        $storedName = (string)TestServer::pdo()->query('SELECT stored_name FROM attachments WHERE id=' . $attachmentId)->fetchColumn();
        $storedFile = TestServer::docRoot() . '/uploads/' . $storedName;
        $this->assertFileExists($storedFile);

        $response = $admin->post('/?page=task&id=' . $id, [
            'action' => 'delete_attachment',
            'attachment_id' => (string)$attachmentId,
        ]);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('?page=task&id=' . $id, (string)$response->header('location'));

        $page = $admin->taskPage($id);
        // The download link is gone; the filename itself remains visible in the
        // history entry (the audit trail keeps the name on purpose).
        $this->assertFalse($page->contains('?page=download&id=' . $attachmentId));
        $this->assertTrue($page->contains('Attachment deleted.'));

        // The row is gone, the download link 404s and the stored file was removed.
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM attachments WHERE id=' . $attachmentId)->fetchColumn());
        $download = $admin->get('/?page=download&id=' . $attachmentId);
        $this->assertSame(404, $download->status);
        $this->assertFileDoesNotExist($storedFile);
    }

    public function test_attachment_deletion_is_limited_to_the_admin_or_the_uploader(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'worker', 'Worker User', 'WorkerPass12345');
        $id = $admin->createTask('Guarded attachment task');
        $attachmentId = $this->uploadTextFile($admin, $id, 'guarded.txt', 'guarded payload');

        $worker = new AppSession('worker', 'WorkerPass12345');
        $response = $worker->post('/?page=task&id=' . $id, [
            'action' => 'delete_attachment',
            'attachment_id' => (string)$attachmentId,
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Only an administrator or the uploader can delete an attachment.'));

        // Nothing was removed: the row, the download and the stored file remain.
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM attachments WHERE id=' . $attachmentId)->fetchColumn());
        $download = $worker->get('/?page=download&id=' . $attachmentId);
        $this->assertSame(200, $download->status);
        $this->assertSame('guarded payload', $download->body);
        // A non-privileged visitor is not offered the delete button either.
        $page = $worker->taskPage($id);
        $this->assertFalse($page->contains('delete_attachment'));
    }

    public function test_admin_can_delete_another_users_attachment(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'uploader', 'Uploader User', 'UploaderPass1234');
        $id = $admin->createTask('Admin cleanup task');

        $uploader = new AppSession('uploader', 'UploaderPass1234');
        $attachmentId = $this->uploadTextFile($uploader, $id, 'from-uploader.txt', 'uploader payload');

        $response = $admin->post('/?page=task&id=' . $id, [
            'action' => 'delete_attachment',
            'attachment_id' => (string)$attachmentId,
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM attachments WHERE id=' . $attachmentId)->fetchColumn());

        $page = $admin->taskPage($id);
        $this->assertFalse($page->contains('?page=download&id=' . $attachmentId));
        $this->assertTrue($page->contains('Attachment deleted.'));
    }

    public function test_deleting_a_missing_attachment_is_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Ghost attachment task');

        $response = $admin->post('/?page=task&id=' . $id, [
            'action' => 'delete_attachment',
            'attachment_id' => '4242',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Attachment not found.'));
    }

    public function test_deleting_a_task_removes_its_attachment_files(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Task with attachments to hard delete');
        $this->uploadTextFile($admin, $id, 'first.txt', 'first payload');
        $this->uploadTextFile($admin, $id, 'second.txt', 'second payload');
        $storedNames = array_map(
            'strval',
            TestServer::pdo()->query('SELECT stored_name FROM attachments ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
        $this->assertCount(2, $storedNames);
        foreach ($storedNames as $storedName) {
            $this->assertFileExists(TestServer::docRoot() . '/uploads/' . $storedName);
        }

        $archive = $admin->post('/?page=task&id=' . $id, ['action' => 'archive_task', 'id' => (string)$id]);
        $this->assertSame(302, $archive->status);
        $delete = $admin->post('/?page=archive', ['action' => 'delete_task', 'id' => (string)$id]);
        $this->assertSame(302, $delete->status);
        $this->assertStringContainsString('?page=archive', (string)$delete->header('location'));
        $this->assertTrue($admin->http->get('/?page=archive')->contains('Task deleted permanently.'));

        // The rows cascaded away and the files are hard-deleted from uploads/.
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM attachments')->fetchColumn());
        foreach ($storedNames as $storedName) {
            $this->assertFileDoesNotExist(TestServer::docRoot() . '/uploads/' . $storedName);
        }
    }

    public function test_cleanup_uploads_removes_only_orphaned_files(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'cleaner', 'Cleaner User', 'CleanerPass1234');
        $id = $admin->createTask('Task keeping a live attachment');
        $attachmentId = $this->uploadTextFile($admin, $id, 'kept.txt', 'kept payload');
        $storedName = (string)TestServer::pdo()->query('SELECT stored_name FROM attachments WHERE id=' . $attachmentId)->fetchColumn();
        $keptFile = TestServer::docRoot() . '/uploads/' . $storedName;
        $orphanFile = TestServer::docRoot() . '/uploads/orphan-leftover-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($orphanFile, 'orphan payload');

        // A non-admin gets the sweep silently skipped: nothing is removed.
        $cleaner = new AppSession('cleaner', 'CleanerPass1234');
        $denied = $cleaner->post('/?page=admin', ['action' => 'cleanup_uploads']);
        $this->assertSame(200, $denied->status);
        $this->assertFalse($denied->contains('orphaned attachment file(s) removed.'));
        $this->assertFileExists($orphanFile);
        $this->assertFileExists($keptFile);

        $response = $admin->post('/?page=admin', ['action' => 'cleanup_uploads']);
        $this->assertSame(302, $response->status);
        // Older tests leave their stored files behind in the shared uploads
        // folder, so the sweep may remove more than the one planted orphan.
        $page = $admin->http->get('/?page=admin');
        $this->assertTrue($page->contains('orphaned attachment file(s) removed.'));
        $this->assertFileDoesNotExist($orphanFile);
        // The referenced file and the .htaccess survive the sweep.
        $this->assertFileExists($keptFile);
        $this->assertFileExists(TestServer::docRoot() . '/uploads/.htaccess');

        $again = $admin->post('/?page=admin', ['action' => 'cleanup_uploads']);
        $this->assertSame(302, $again->status);
        $this->assertTrue($admin->http->get('/?page=admin')->contains('No orphaned attachment files found.'));
    }

    /** Uploads a small text file through the real form and returns the new attachment id. */
    private function uploadTextFile(AppSession $session, int $taskId, string $filename, string $content): int
    {
        $file = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($file, $content);
        $response = $session->upload('/?page=task&id=' . $taskId, [
            'action' => 'upload',
            'task_id' => (string)$taskId,
        ], 'file', $file, $filename, 'text/plain');
        unlink($file);
        $this->assertSame(302, $response->status);

        $s = TestServer::pdo()->prepare('SELECT id FROM attachments WHERE task_id=? AND original_name=? ORDER BY id DESC');
        $s->execute([$taskId, $filename]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'Attachment ' . $filename . ' was not stored.');
        return (int)$id;
    }

    /** Adds a plain user through the admin page so a second session can log in. */
    private function createUser(AppSession $admin, string $username, string $display, string $password): void
    {
        $response = $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => $display,
            'password' => $password,
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status);
    }
}
