<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use ZipArchive;

/**
 * Task #49 - the full backup download on the Software updates page.
 *
 * The button produces one ZIP with the application code (including
 * config.php), every uploaded attachment file and an SQL dump of the whole
 * database. These tests pin the access control around it, the archive layout
 * and the fact that the backup restores everything that was put on the board
 * before it was taken.
 */
final class BackupDownloadTest extends E2eTestCase
{
    public function test_updates_page_offers_the_full_backup_download(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=updates');

        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Download a full backup'), 'The page must present the full backup section.');
        $this->assertTrue($page->contains('name="action" value="download_backup"'), 'The backup form must POST the download_backup action.');
        $this->assertTrue($page->contains('name="current_password"'), 'The backup form must re-check the administrator password.');
        $this->assertTrue($page->contains('Download full backup'), 'The backup button must be labelled with what it downloads.');
    }

    public function test_backup_rejects_a_wrong_admin_password(): void
    {
        $admin = $this->adminSession();

        $response = $admin->post('/?page=updates', [
            'action' => 'download_backup',
            'current_password' => 'NotTheAdminPassword1',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Your current administrator password was not accepted.'), 'A wrong password must surface the page error.');
        $this->assertStringStartsWith('text/html', (string)$response->header('content-type'), 'A rejected backup must render the page, not stream a ZIP.');
        $this->assertNull($response->header('content-disposition'), 'A rejected backup must not carry a download disposition.');
    }

    public function test_backup_requires_a_valid_csrf_token(): void
    {
        $admin = $this->adminSession();

        $response = $admin->postWithoutCsrf('/?page=updates', [
            'action' => 'download_backup',
            'current_password' => 'AdminPass1234',
        ]);

        $this->assertSame(403, $response->status);
        $this->assertTrue($response->contains('Invalid CSRF token.'), 'The global CSRF gate must run before the backup handler.');
    }

    public function test_non_admins_cannot_download_a_backup(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => 'alice',
            'display_name' => 'Alice',
            'password' => 'AlicePass12345',
            'role' => 'user',
        ]);
        $alice = new AppSession('alice', 'AlicePass12345');

        $response = $alice->post('/?page=updates', [
            'action' => 'download_backup',
            'current_password' => 'AlicePass12345',
        ]);

        $this->assertSame(403, $response->status, 'The updates page must stay admin-only for POSTs too.');
        $this->assertTrue($response->contains('Forbidden.'));

        $anonymous = new AppSession();
        $this->assertSame(302, $anonymous->post('/?page=updates', ['action' => 'download_backup'])->status, 'Anonymous requests must be pushed to the sign-in page.');
    }

    public function test_admin_downloads_a_complete_backup_zip(): void
    {
        $admin = $this->adminSession();

        // Put real content on the board first: a task and a text attachment,
        // so the dump and the uploads folder of the backup have something to
        // prove themselves with.
        $taskId = $admin->createTask('Backup target task', ['description' => 'Backup description payload 49']);
        $file = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($file, 'backup attachment payload 123');
        $upload = $admin->upload('/?page=task&id=' . $taskId, [
            'action' => 'upload',
            'task_id' => (string)$taskId,
        ], 'file', $file, 'report.txt', 'text/plain');
        $this->assertSame(302, $upload->status, 'The attachment upload itself must succeed.');

        $response = $admin->post('/?page=updates', [
            'action' => 'download_backup',
            'current_password' => 'AdminPass1234',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/zip', (string)$response->header('content-type'));
        $this->assertStringStartsWith('attachment; filename="task-board-backup-', (string)$response->header('content-disposition'));
        $this->assertNotSame('', $response->body, 'The ZIP body must not be empty.');

        // Open the delivered ZIP and check the promised layout.
        $zipFile = tempnam(sys_get_temp_dir(), 'backup-assert-');
        file_put_contents($zipFile, $response->body);
        $zip = new ZipArchive();
        try {
            $this->assertTrue($zip->open($zipFile, ZipArchive::CHECKCONS) === true, 'The streamed body must be a structurally valid ZIP.');
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string)$zip->getNameIndex($i);
            }
            // The SQLite test deployment ships only the four core files into
            // its temp doc root, so exactly those must appear under code/ —
            // on a real installation the code/ folder mirrors the full upload.
            foreach (['MANIFEST.txt', 'code/index.php', 'code/updater.php', 'code/src/helpers.php', 'code/config.php', 'uploads/.htaccess'] as $expected) {
                $this->assertContains($expected, $names, "The archive must contain {$expected}.");
            }
            $uploaded = array_values(array_filter($names, fn(string $n): bool => str_starts_with($n, 'uploads/') && $n !== 'uploads/.htaccess'));
            $this->assertNotEmpty($uploaded, 'The uploaded attachment file must sit under uploads/.');
            // The uploads folder is shared across the whole test process (only
            // the database is reset between tests), so the archive may carry
            // files from earlier tests too — what matters is that the file
            // uploaded right before the backup round-trips byte for byte.
            $payloads = [];
            foreach ($uploaded as $entry) {
                $payloads[] = (string)$zip->getFromName($entry);
            }
            $this->assertContains('backup attachment payload 123', $payloads, 'The file uploaded before the backup must round-trip byte for byte.');

            // The code copy must be the real served code, not a stub.
            $this->assertSame(
                (string)file_get_contents(TestServerDocRoot() . '/index.php'),
                (string)$zip->getFromName('code/index.php'),
                'code/index.php must be byte-identical to the served application file.'
            );
            $this->assertSame(
                (string)file_get_contents(TestServerDocRoot() . '/config.php'),
                (string)$zip->getFromName('code/config.php'),
                'code/config.php must carry the live configuration so the backup is restorable.'
            );

            // Nothing that does not belong into a restorable archive.
            foreach ($names as $name) {
                $this->assertStringStartsNotWith('.git', $name, 'The archive must not contain .git paths.');
                $this->assertStringNotContainsString('board.sqlite', $name, 'The SQLite test artifact must not be backed up.');
            }

            // The SQL dump: schema + the data that was just put on the board.
            $dumps = array_values(array_filter($names, fn(string $n): bool => str_starts_with($n, 'database/') && str_ends_with($n, '.sql')));
            $this->assertCount(1, $dumps, 'Exactly one SQL dump must sit under database/.');
            $dump = (string)$zip->getFromName($dumps[0]);
            $this->assertStringContainsString('CREATE TABLE', $dump, 'The dump must contain the schema.');
            $this->assertStringContainsString('INSERT INTO', $dump, 'The dump must contain the data.');
            $this->assertStringContainsString('Backup target task', $dump, 'The dump must contain the task created before the backup.');
            $this->assertStringContainsString('Backup description payload 49', $dump, 'The dump must contain the task description created before the backup.');
            $this->assertMatchesRegularExpression('/\$2y\$10\$/', $dump, 'The dump must contain the password hashes, not just the visible data.');
            $this->assertStringContainsString('report.txt', $dump, 'The dump must contain the attachment metadata row.');
            $manifest = (string)$zip->getFromName('MANIFEST.txt');
            $this->assertStringContainsString('code/', $manifest);
            $this->assertStringContainsString('database/', $manifest);
        } finally {
            $zip->close();
            @unlink($zipFile);
            @unlink($file);
        }

        // The backup lives in the temp directory only: nothing may leak into
        // the served document root (it would sit next to config.php and be
        // publicly readable on a shared host).
        foreach (['task-board-backup-', 'tb-backup-', 'tb-dump-'] as $prefix) {
            $this->assertEmpty(
                glob(TestServerDocRoot() . '/' . $prefix . '*'),
                "No {$prefix}* backup artefact may be left in the document root."
            );
        }
        $this->assertEmpty(
            array_filter(glob(TestServerDocRoot() . '/*.zip') ?: [], fn(string $f): bool => str_contains(basename($f), 'backup')),
            'No backup ZIP may be left in the document root.'
        );
    }
}

/** The test server document root, resolved without importing the namespace noise. */
function TestServerDocRoot(): string
{
    return \TaskBoard\Tests\Support\TestServer::docRoot();
}
