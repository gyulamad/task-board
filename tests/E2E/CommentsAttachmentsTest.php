<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

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
}
