<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Attachment upload date (#40): every attachment on a task shows when it was
 * uploaded, rendered next to the uploader and size in the Attachments card.
 */
final class AttachmentDateTest extends E2eTestCase
{
    public function test_attachments_show_their_upload_date_and_time(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Dated attachment task');
        $first = $this->uploadTextFile($admin, $id, 'dated-one.txt', 'first payload');
        $second = $this->uploadTextFile($admin, $id, 'dated-two.txt', 'second payload');

        // #108: the stored stamp is an epoch second and the page renders it
        // in the viewer's timezone through tb_dt() - compare against the same
        // helper output the markup pipeline produces.
        $firstStamp = (int)TestServer::pdo()->query('SELECT created_at FROM attachments WHERE id=' . $first)->fetchColumn();
        $secondStamp = (int)TestServer::pdo()->query('SELECT created_at FROM attachments WHERE id=' . $second)->fetchColumn();
        $this->assertGreaterThan(0, $firstStamp);

        $page = $admin->taskPage($id);
        // Both attachments render their own upload stamp, in the board's usual
        // "YYYY-MM-DD HH:MM:SS" format, right after the size.
        $this->assertTrue($page->contains('KB · ' . (new \DateTime('@' . $firstStamp))->format('Y-m-d H:i:s')), 'First attachment misses its upload date.');
        $this->assertTrue($page->contains('KB · ' . (new \DateTime('@' . $secondStamp))->format('Y-m-d H:i:s')), 'Second attachment misses its upload date.');
    }

    public function test_attachment_date_stays_visible_for_other_users(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'viewer', 'Viewer User', 'ViewerPass12345');
        $id = $admin->createTask('Shared attachment task');
        $attachmentId = $this->uploadTextFile($admin, $id, 'shared.txt', 'shared payload');
        $stamp = (int)TestServer::pdo()->query('SELECT created_at FROM attachments WHERE id=' . $attachmentId)->fetchColumn();

        // The upload date is task information, not uploader-private: a plain
        // user who cannot delete the attachment still sees the stamp.
        $viewer = new AppSession('viewer', 'ViewerPass12345');
        $page = $viewer->taskPage($id);
        $this->assertTrue($page->contains('KB · ' . (new \DateTime('@' . $stamp))->format('Y-m-d H:i:s')));
        $this->assertFalse($page->contains('delete_attachment'));
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
