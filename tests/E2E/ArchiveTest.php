<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * End-to-end coverage for the task archive (#12).
 *
 * Deleting a task now archives it first: archived tasks disappear from the task
 * list but stay viewable on an archive page where they can be restored or, by an
 * administrator or the creator, deleted permanently.
 */
final class ArchiveTest extends E2eTestCase
{
    private function archive(AppSession $session, int $taskId, string $return = ''): \TaskBoard\Tests\Support\HttpResponse
    {
        return $session->post('/?page=task&id=' . $taskId, array_filter([
            'action' => 'archive_task',
            'id' => (string)$taskId,
            'return' => $return,
        ], fn($v) => $v !== ''));
    }

    private function restore(AppSession $session, int $taskId): \TaskBoard\Tests\Support\HttpResponse
    {
        return $session->post('/?page=task&id=' . $taskId, ['action' => 'restore_task', 'id' => (string)$taskId]);
    }

    private function archivedAt(int $taskId): ?string
    {
        return TestServer::pdo()->query('SELECT archived_at FROM tasks WHERE id=' . $taskId)->fetchColumn();
    }

    private function createScheduledDoneTask(AppSession $admin, string $title, string $period): int
    {
        $id = $admin->createTask($title);
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => $title,
            'description' => '',
            'status_id' => '3',
            'parent_id' => '',
            'schedule_period' => $period,
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);
        return $id;
    }

    private function backdateTask(int $taskId, string $sqliteModifier): void
    {
        $pdo = TestServer::pdo();
        if (TestServer::dbMode() === 'mysql') {
            $pdo->exec('UPDATE tasks SET updated_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id=' . $taskId);
        } else {
            $pdo->exec("UPDATE tasks SET updated_at = datetime('now', '" . $sqliteModifier . "') WHERE id=" . $taskId);
        }
    }

    public function test_archiving_hides_task_from_list_but_it_stays_viewable(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('To be archived');

        $response = $this->archive($admin, $id);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('page=task&id=' . $id, (string)$response->header('location'));

        $list = $admin->get('/?');
        $this->assertFalse($list->contains('#' . $id . ' · To be archived'));
        $this->assertNotNull($this->archivedAt($id));

        $page = $admin->taskPage($id);
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('To be archived'));
        $this->assertTrue($page->contains('Archived'));
        $this->assertTrue($page->contains('Restore task'));
        $this->assertFalse($page->contains('Edit task</a>'), 'An archived task must not offer the Edit button.');
    }

    public function test_archive_page_lists_archived_tasks_and_empty_state(): void
    {
        $admin = $this->adminSession();
        $empty = $admin->get('/?page=archive');
        $this->assertTrue($empty->contains('The archive is empty.'));

        $first = $admin->createTask('Archived one');
        $second = $admin->createTask('Archived two');
        $this->archive($admin, $first);
        $this->archive($admin, $second);

        $page = $admin->get('/?page=archive');
        $this->assertTrue($page->contains('2 archived tasks'));
        $this->assertTrue($page->contains('#' . $first . ' · Archived one'));
        $this->assertTrue($page->contains('#' . $second . ' · Archived two'));
        $this->assertTrue($page->contains('Restore'));
        $this->assertFalse($page->contains('The archive is empty.'));
    }

    public function test_restore_returns_task_to_the_list(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Restorable task');
        $this->archive($admin, $id);

        $response = $this->restore($admin, $id);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('page=task&id=' . $id, (string)$response->header('location'));

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('Task restored.'));
        $this->assertFalse($page->contains('>Archived</span>'));
        $this->assertNull($this->archivedAt($id));
        $this->assertTrue($admin->get('/?')->contains('#' . $id . ' · Restorable task'));
    }

    public function test_hard_delete_requires_archive_and_permissions(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Delete me later');

        // Hard delete on an active task must be refused: archive first.
        $refused = $admin->post('/?page=task&id=' . $id, ['action' => 'delete_task', 'id' => (string)$id, 'return' => '?page=archive']);
        $this->assertTrue($refused->contains('Archive the task before deleting it permanently.'));
        $this->assertSame('1', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $id)->fetchColumn());

        $this->archive($admin, $id);

        // A non-creator, non-admin user cannot hard delete it either.
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'carol',
            'display_name' => 'Carol User',
            'password' => 'CarolPass12345',
            'role' => 'user',
        ]);
        $carol = new AppSession('carol', 'CarolPass12345');
        $denied = $carol->post('/?page=task&id=' . $id, ['action' => 'delete_task', 'id' => (string)$id, 'return' => '?page=archive']);
        $this->assertTrue($denied->contains('Only an administrator or task creator can delete this task.'));
        $this->assertSame('1', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $id)->fetchColumn());

        // Carol cannot archive somebody else's task either.
        $carolsView = $admin->createTask('Admins own task');
        $deniedArchive = $carol->post('/?page=task&id=' . $carolsView, ['action' => 'archive_task', 'id' => (string)$carolsView]);
        $this->assertTrue($deniedArchive->contains('Only an administrator or task creator can archive this task.'));

        // The creator deletes it permanently from the archive and stays there.
        $deleted = $admin->post('/?page=task&id=' . $id, ['action' => 'delete_task', 'id' => (string)$id, 'return' => '?page=archive']);
        $this->assertSame(302, $deleted->status);
        $this->assertSame('?page=archive', (string)$deleted->header('location'));
        $after = $admin->get('/?page=archive');
        $this->assertTrue($after->contains('Task deleted permanently.'));
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $id)->fetchColumn());
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM comments WHERE task_id=' . $id)->fetchColumn());
    }

    public function test_bulk_archive_moves_selected_tasks_to_the_archive(): void
    {
        $admin = $this->adminSession();
        $first = $admin->createTask('Bulk one');
        $second = $admin->createTask('Bulk two');
        $third = $admin->createTask('Stays visible');

        $response = $admin->post('/?', [
            'action' => 'bulk_archive_tasks',
            'return' => '?',
            'task_ids' => [(string)$first, (string)$second],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?', (string)$response->header('location'));

        $list = $admin->get('/?');
        $this->assertTrue($list->contains('2 tasks archived.'));
        $this->assertFalse($list->contains('#' . $first . ' · Bulk one'));
        $this->assertFalse($list->contains('#' . $second . ' · Bulk two'));
        $this->assertTrue($list->contains('#' . $third . ' · Stays visible'));

        $archive = $admin->get('/?page=archive');
        $this->assertTrue($archive->contains('#' . $first . ' · Bulk one'));
        $this->assertTrue($archive->contains('#' . $second . ' · Bulk two'));
    }

    public function test_archived_tasks_are_excluded_from_picklists(): void
    {
        $admin = $this->adminSession();
        $parent = $admin->createTask('Archived parent');
        $child = $admin->createTask('Child task');
        $this->archive($admin, $parent);

        $newPage = $admin->get('/?page=new');
        $this->assertFalse($newPage->contains('#' . $parent . ' - Archived parent</option>'), 'Archived tasks must not be selectable as parent.');

        $taskPage = $admin->taskPage($child);
        $this->assertFalse($taskPage->contains('#' . $parent . ' - Archived parent</option>'), 'Archived tasks must not be selectable as relationship target.');
    }

    public function test_archived_task_cannot_be_edited_until_restored(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Read only once archived');
        $this->archive($admin, $id);

        $editPage = $admin->get('/?page=edit&id=' . $id);
        $this->assertTrue($editPage->contains('This task is archived'));
        $this->assertTrue($editPage->contains('Restore now'));
        $this->assertFalse($editPage->contains('name="title"'));

        // Restore directly from the edit page, then the form renders again.
        $restore = $admin->post('/?page=edit&id=' . $id, ['action' => 'restore_task', 'id' => (string)$id]);
        $this->assertSame(302, $restore->status);
        $editAgain = $admin->get('/?page=edit&id=' . $id);
        $this->assertTrue($editAgain->contains('name="title"'));
        $this->assertTrue($editAgain->contains('Read only once archived'));
    }

    public function test_scheduler_ignores_archived_scheduled_tasks(): void
    {
        $admin = $this->adminSession();
        $id = $this->createScheduledDoneTask($admin, 'Scheduled and archived', 'hourly');
        $this->backdateTask($id, '-2 hours');

        // Archived: the cron must not reopen it, not even though it is overdue.
        $this->archive($admin, $id);
        $cron = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('nothing to reopen', $cron->body);
        $this->assertSame('3', (string)TestServer::pdo()->query('SELECT status_id FROM tasks WHERE id=' . $id)->fetchColumn());
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM comments WHERE task_id=' . $id)->fetchColumn());

        // After restoring, the refreshed updated_at keeps it safe from the cron too.
        $this->restore($admin, $id);
        $cron = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('nothing to reopen', $cron->body);
        $this->assertSame('3', (string)TestServer::pdo()->query('SELECT status_id FROM tasks WHERE id=' . $id)->fetchColumn());
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM comments WHERE task_id=' . $id)->fetchColumn());
    }

    public function test_archiving_and_restoring_records_history(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('History check');
        $this->archive($admin, $id);
        $this->restore($admin, $id);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('Task archived'));
        $this->assertTrue($page->contains('Task restored'));
        $events = TestServer::pdo()->query("SELECT event FROM task_history WHERE task_id={$id} ORDER BY id")->fetchAll();
        $this->assertContains('Task archived', array_column($events, 'event'));
        $this->assertContains('Task restored', array_column($events, 'event'));
    }
}
