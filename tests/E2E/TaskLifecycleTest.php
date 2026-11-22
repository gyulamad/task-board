<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

final class TaskLifecycleTest extends E2eTestCase
{
    public function test_creating_a_task_redirects_to_its_page_and_records_history(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Write the report', ['description' => 'Quarterly report body']);

        $page = $admin->taskPage($id);
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Write the report'));
        $this->assertTrue($page->contains('Quarterly report body'));
        $this->assertTrue($page->contains('Task created'));
        $this->assertTrue($page->contains('TODO'));

        $row = TestServer::pdo()->query('SELECT title, description, status_id, created_by FROM tasks WHERE id=' . $id)->fetch();
        $this->assertSame('Write the report', $row['title']);
        $this->assertSame(1, (int)$row['status_id']);
    }

    public function test_tasks_require_a_title(): void
    {
        $admin = $this->adminSession();
        $admin->get('/?page=new');
        $response = $admin->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => '   ',
            'description' => '',
            'status_id' => '1',
            'parent_id' => '',
            'assignees' => [],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Task title is required.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
    }

    public function test_invalid_status_is_rejected(): void
    {
        $admin = $this->adminSession();
        $admin->get('/?page=new');
        $response = $admin->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => 'Valid title',
            'description' => '',
            'status_id' => '999',
            'parent_id' => '',
            'assignees' => [],
        ]);
        $this->assertTrue($response->contains('Choose a valid status.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
    }

    public function test_editing_a_task_updates_fields_and_history(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Original title');

        $editPage = $admin->get('/?page=edit&id=' . $id);
        $this->assertSame(200, $editPage->status);
        $this->assertTrue($editPage->contains('Original title'));

        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Renamed title',
            'description' => 'Updated description',
            'status_id' => '2',
            'parent_id' => '',
            'assignees' => ['1'],
        ]);
        $this->assertSame(302, $response->status);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('Renamed title'));
        $this->assertTrue($page->contains('Updated description'));
        $this->assertTrue($page->contains('IN PROGRESS'));
        $this->assertTrue($page->contains('Task updated'));
        $this->assertTrue($page->contains('Administrator')); // assignee display name

        $assignees = TestServer::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id=' . $id)->fetchColumn();
        $this->assertSame('1', (string)$assignees);
    }

    public function test_task_can_have_a_parent_and_rejects_itself_as_parent(): void
    {
        $admin = $this->adminSession();
        $parentId = $admin->createTask('Parent epic');
        $childId = $admin->createTask('Child story');

        $response = $admin->post('/?page=edit&id=' . $childId, [
            'action' => 'save_task',
            'id' => (string)$childId,
            'title' => 'Child story',
            'description' => '',
            'status_id' => '1',
            'parent_id' => (string)$parentId,
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);
        $page = $admin->taskPage($childId);
        $this->assertTrue($page->contains('Parent:'));
        $this->assertTrue($page->contains('page=task&id=' . $parentId));

        $selfParent = $admin->post('/?page=edit&id=' . $childId, [
            'action' => 'save_task',
            'id' => (string)$childId,
            'title' => 'Child story',
            'description' => '',
            'status_id' => '1',
            'parent_id' => (string)$childId,
            'assignees' => [],
        ]);
        $this->assertTrue($selfParent->contains('Invalid parent task.'));

        $missingParent = $admin->post('/?page=edit&id=' . $childId, [
            'action' => 'save_task',
            'id' => (string)$childId,
            'title' => 'Child story',
            'description' => '',
            'status_id' => '1',
            'parent_id' => '9999',
            'assignees' => [],
        ]);
        $this->assertTrue($missingParent->contains('Invalid parent task.'));
    }

    public function test_creating_task_deleting_task_by_administrator(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Doomed task');

        // Deleting now archives first (#12); the permanent delete happens from the archive.
        $archive = $admin->post('/?page=task&id=' . $id, ['action' => 'archive_task', 'id' => (string)$id]);
        $this->assertSame(302, $archive->status);
        $this->assertSame('?page=task&id=' . $id, (string)$archive->header('location'));

        $after = $admin->get('/?page=task&id=' . $id);
        $this->assertTrue($after->contains('Task archived. Find it under Archive.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks')->fetchColumn());

        $delete = $admin->post('/?page=task&id=' . $id, ['action' => 'delete_task', 'id' => (string)$id, 'return' => '?page=archive']);
        $this->assertSame(302, $delete->status);
        $this->assertSame('?page=archive', (string)$delete->header('location'));

        $this->assertTrue($admin->get('/?page=archive')->contains('Task deleted permanently.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
        $this->assertSame(404, $admin->taskPage($id)->status);
    }

    public function test_only_administrators_or_the_creator_can_delete_a_task(): void
    {
        $admin = $this->adminSession();
        $ownTask = $admin->createTask('Admin task');
        $userTask = $admin->createTask('User task');
        TestServer::pdo()->exec('UPDATE tasks SET created_by=1 WHERE id=' . $userTask);

        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'carol',
            'display_name' => 'Carol User',
            'password' => 'CarolPass12345',
            'role' => 'user',
        ]);
        $carol = new \TaskBoard\Tests\Support\AppSession('carol', 'CarolPass12345');

        // Carol cannot archive somebody else's task...
        $deniedArchive = $carol->post('/?page=task&id=' . $ownTask, ['action' => 'archive_task', 'id' => (string)$ownTask]);
        $this->assertSame(200, $deniedArchive->status);
        $this->assertTrue($deniedArchive->contains('Only an administrator or task creator can archive this task.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $ownTask)->fetchColumn());

        // ...and even an archived task of the administrator cannot be hard-deleted by her.
        $admin->post('/?page=task&id=' . $ownTask, ['action' => 'archive_task', 'id' => (string)$ownTask]);
        $denied = $carol->post('/?page=task&id=' . $ownTask, ['action' => 'delete_task', 'id' => (string)$ownTask, 'return' => '?page=archive']);
        $this->assertSame(200, $denied->status);
        $this->assertTrue($denied->contains('Only an administrator or task creator can delete this task.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $ownTask)->fetchColumn());

        // Give carol a task of her own: she may archive and then delete that one.
        $carolsTask = $carol->createTask('Carols own task');
        $carol->post('/?page=task&id=' . $carolsTask, ['action' => 'archive_task', 'id' => (string)$carolsTask]);
        $allowed = $carol->post('/?page=task&id=' . $carolsTask, ['action' => 'delete_task', 'id' => (string)$carolsTask, 'return' => '?page=archive']);
        $this->assertSame(302, $allowed->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $carolsTask)->fetchColumn());

        // Deleting the other task's id by carol stays blocked (the archive still shows it after).
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $ownTask)->fetchColumn());
    }

    public function test_the_task_list_shows_created_tasks(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Visible on the list');
        $list = $admin->get('/?');
        $this->assertTrue($list->contains('#' . $id . ' · Visible on the list'));
    }

    public function test_edit_form_renders_assignees_as_checkboxes(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Checkbox task', ['assignees' => ['1']]);

        $editPage = $admin->get('/?page=edit&id=' . $id);
        $this->assertSame(200, $editPage->status);
        // Assignees are rendered as checkboxes so they can be unticked (unassigned).
        $this->assertTrue($editPage->contains('type="checkbox" name="assignees[]"'));
        // User #1 (Administrator) is currently assigned, so their box is pre-ticked.
        $this->assertTrue($editPage->contains('value="1" id="assignee-1" checked'));
        // The old undiscoverable multi-select is gone.
        $this->assertFalse($editPage->contains('name="assignees[]" multiple'));
    }

    public function test_task_can_be_unassigned_by_saving_the_edit_form_without_assignees(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Unassign me', ['assignees' => ['1']]);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('Assignees:'));
        $this->assertTrue($page->contains('Administrator'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id=' . $id)->fetchColumn());

        // Saving the edit form with every assignee checkbox unticked: the browser
        // omits the assignees[] field entirely, which must clear all assignees.
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Unassign me',
            'description' => 'Created by the test suite',
            'status_id' => '1',
            'parent_id' => '',
        ]);
        $this->assertSame(302, $response->status);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('Unassigned'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id=' . $id)->fetchColumn());

        // Re-assigning through the same form still works afterwards.
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Unassign me',
            'description' => 'Created by the test suite',
            'status_id' => '1',
            'parent_id' => '',
            'assignees' => ['1'],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id=' . $id)->fetchColumn());
        $this->assertTrue($admin->taskPage($id)->contains('Administrator'));
    }
}
