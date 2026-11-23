<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #8: statuses are editable and deletable. Task #42 moved the editor
 * from the single Administration page to its own Task statuses page
 * (?page=statuses) and added the per-status colour — this suite keeps pinning
 * the edit/delete/validate behaviour, now against the new page.
 */
final class StatusAdminTest extends E2eTestCase
{
    /** Adds a status through the real Task statuses form and returns its id. */
    private function addStatus(AppSession $admin, string $name, bool $done = false): int
    {
        $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => $name] + ($done ? ['is_done' => '1'] : []));
        $q = TestServer::pdo()->prepare('SELECT id FROM statuses WHERE name=?');
        $q->execute([$name]);
        $id = $q->fetchColumn();
        $q->closeCursor(); // release the read lock before the next app write
        $this->assertNotFalse($id, 'Status ' . $name . ' should exist after add_status');
        return (int)$id;
    }

    private function statusName(int $id): ?string
    {
        $q = TestServer::pdo()->prepare('SELECT name FROM statuses WHERE id=?');
        $q->execute([$id]);
        $name = $q->fetchColumn() ?: null;
        $q->closeCursor();
        return $name;
    }

    private function isDone(int $id): int
    {
        $q = TestServer::pdo()->prepare('SELECT is_done FROM statuses WHERE id=?');
        $q->execute([$id]);
        $done = (int)$q->fetchColumn();
        $q->closeCursor();
        return $done;
    }

    public function test_statuses_page_offers_edit_and_delete_for_every_status(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=statuses');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('action" value="edit_status"'));
        $this->assertTrue($page->contains('action" value="delete_status"'));
        foreach (['TODO', 'IN PROGRESS', 'DONE'] as $name) {
            $this->assertTrue($page->contains('value="' . $name . '"'));
        }
        $this->assertFalse($page->contains('cannot currently be renamed or deleted'));
    }

    public function test_admin_can_rename_a_status(): void
    {
        $admin = $this->adminSession();
        $id = $this->addStatus($admin, 'BLOCKED');

        $response = $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'STOPPED', 'sort_order' => '4',
        ]);
        $this->assertSame(302, $response->status);

        $page = $admin->get('/?page=statuses');
        $this->assertTrue($page->contains('Status updated.'));
        $this->assertTrue($page->contains('STOPPED'));
        $this->assertFalse($page->contains('BLOCKED'));
        $this->assertSame('STOPPED', $this->statusName($id));
    }

    public function test_renamed_status_keeps_its_tasks(): void
    {
        $admin = $this->adminSession();
        $id = $this->addStatus($admin, 'BLOCKED');
        $taskId = $admin->createTask('Rename survivor', ['status_id' => (string)$id]);

        $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'STOPPED', 'sort_order' => '4',
        ]);

        $this->assertTrue($admin->taskPage($taskId)->contains('STOPPED'));
        $editForm = $admin->get('/?page=edit&id=' . $taskId);
        $this->assertTrue($editForm->contains('STOPPED'));
    }

    public function test_duplicate_status_names_are_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $this->addStatus($admin, 'BLOCKED');

        $response = $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'DONE', 'sort_order' => '4',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('A status with this name already exists.'));
        $this->assertSame('BLOCKED', $this->statusName($id));

        // Keeping its own name while editing must not count as a duplicate.
        $response = $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'BLOCKED', 'sort_order' => '7',
        ]);
        $this->assertSame(302, $response->status);
        $q = TestServer::pdo()->prepare('SELECT sort_order FROM statuses WHERE id=?');
        $q->execute([$id]);
        $this->assertSame('7', (string)$q->fetchColumn());
    }

    public function test_empty_and_too_long_status_names_are_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $this->addStatus($admin, 'BLOCKED');

        $empty = $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id, 'name' => '   ', 'sort_order' => '4',
        ]);
        $this->assertSame(200, $empty->status);
        $this->assertTrue($empty->contains('Status name is required.'));

        $tooLong = $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => str_repeat('x', 41), 'sort_order' => '4',
        ]);
        $this->assertSame(200, $tooLong->status);
        $this->assertTrue($tooLong->contains('Status name must be 40 characters or fewer.'));
        $this->assertSame('BLOCKED', $this->statusName($id));
    }

    public function test_completion_flag_can_be_toggled(): void
    {
        $admin = $this->adminSession();
        $id = $this->addStatus($admin, 'SHIPPED', true);
        $this->assertSame(1, $this->isDone($id));

        // Unmark: post without the is_done checkbox.
        $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'SHIPPED', 'sort_order' => '4',
        ]);
        $this->assertSame(0, $this->isDone($id));

        // Mark again.
        $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'SHIPPED', 'sort_order' => '4', 'is_done' => '1',
        ]);
        $this->assertSame(1, $this->isDone($id));
        $this->assertTrue($admin->get('/?page=statuses')->contains('Status updated.'));
    }

    public function test_sort_order_controls_the_display_order(): void
    {
        $admin = $this->adminSession();
        $inProgressId = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='IN PROGRESS'")->fetchColumn();

        $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$inProgressId,
            'name' => 'IN PROGRESS', 'sort_order' => '0',
        ]);

        $newTaskPage = $admin->get('/?page=new');
        $this->assertSame(200, $newTaskPage->status);
        $this->assertTrue(
            (bool)preg_match('/name="status_id"[^>]*><option value="\d+"[^>]*>IN PROGRESS</', $newTaskPage->body),
            'IN PROGRESS (sort_order 0) should be the first status option'
        );
    }

    public function test_status_used_by_tasks_cannot_be_deleted(): void
    {
        $admin = $this->adminSession();
        $id = $this->addStatus($admin, 'BLOCKED');
        $taskId = $admin->createTask('Still blocked', ['status_id' => (string)$id]);

        $response = $admin->post('/?page=statuses', ['action' => 'delete_status', 'id' => (string)$id]);
        $this->assertSame(200, $response->status);
        // Quotes are HTML-escaped in the rendered alert.
        $this->assertTrue($response->contains('&#039;BLOCKED&#039;: 1 task still uses this status.'));
        $this->assertSame('BLOCKED', $this->statusName($id));
        $this->assertTrue($admin->taskPage($taskId)->contains('Still blocked'));
    }

    public function test_unused_status_can_be_deleted(): void
    {
        $admin = $this->adminSession();
        $id = $this->addStatus($admin, 'BLOCKED');

        $response = $admin->post('/?page=statuses', ['action' => 'delete_status', 'id' => (string)$id]);
        $this->assertSame(302, $response->status);

        $page = $admin->get('/?page=statuses');
        $this->assertTrue($page->contains('Status deleted.'));
        $this->assertFalse($page->contains('BLOCKED'));
        $this->assertNull($this->statusName($id));
    }

    public function test_last_remaining_status_cannot_be_deleted(): void
    {
        $admin = $this->adminSession();
        $ids = [];
        foreach (['DONE', 'IN PROGRESS'] as $name) {
            $q = TestServer::pdo()->prepare('SELECT id FROM statuses WHERE name=?');
            $q->execute([$name]);
            $ids[$name] = (int)$q->fetchColumn();
            $q->closeCursor();
        }

        $this->assertSame(302, $admin->post('/?page=statuses', ['action' => 'delete_status', 'id' => (string)$ids['DONE']])->status);
        $this->assertSame(302, $admin->post('/?page=statuses', ['action' => 'delete_status', 'id' => (string)$ids['IN PROGRESS']])->status);

        $q = TestServer::pdo()->query("SELECT id FROM statuses WHERE name='TODO'");
        $todoId = (int)$q->fetchColumn();
        $q->closeCursor();
        $response = $admin->post('/?page=statuses', ['action' => 'delete_status', 'id' => (string)$todoId]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('at least one status must remain.'));
        $this->assertSame('TODO', $this->statusName($todoId));

        // The board stays usable with the one remaining status.
        $taskId = $admin->createTask('Last status works');
        $this->assertTrue($admin->taskPage($taskId)->contains('TODO'));
    }

    public function test_deleting_an_unknown_status_is_an_error(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=statuses', ['action' => 'delete_status', 'id' => '999']);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Status not found.'));
    }

    public function test_regular_users_cannot_edit_or_delete_statuses(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=statuses', [
            'action' => 'add_user',
            'username' => 'carl', 'display_name' => 'Carl User',
            'password' => 'CarlPass123456', 'role' => 'user',
        ]);
        $carl = new AppSession('carl', 'CarlPass123456');

        $carl->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => '1', 'name' => 'HACKED', 'sort_order' => '0',
        ]);
        $carl->post('/?page=statuses', ['action' => 'delete_status', 'id' => '1']);

        $this->assertSame('TODO', $this->statusName(1));
        $this->assertSame(
            3,
            (int)TestServer::pdo()->query('SELECT COUNT(*) FROM statuses')->fetchColumn()
        );
    }
}
