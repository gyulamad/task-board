<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

/**
 * Task #46: a Clone button on the task edit page that opens the task create
 * page prefilled from the cloned task. The prefilled form can be modified and
 * saved (or cancelled) like any other create - saving runs the regular
 * save_task action and produces a brand-new task.
 */
final class CloneTaskTest extends E2eTestCase
{
    public function test_the_edit_page_offers_a_clone_button_pointing_at_the_prefilled_create_page(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Original item');

        $edit = $admin->get('/?page=edit&id=' . $taskId);
        $this->assertSame(200, $edit->status);
        $this->assertStringContainsString('href="?page=new&clone=' . $taskId . '"', $edit->body, 'The Clone button opens the create page with the source task.');

        // The create page without a source has no clone link (nothing to clone).
        $fresh = $admin->get('/?page=new');
        $this->assertStringNotContainsString('href="?page=new&clone=', $fresh->body);
    }

    public function test_the_create_page_prefills_every_form_field_from_the_source(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=project_new', [
            'action' => 'save_project', 'id' => '0', 'name' => 'Clone project',
            'description' => 'x', 'status_id' => '1', 'assignees' => [],
        ]);
        $projectId = (int)TestServer::pdo()->query("SELECT id FROM projects WHERE name='Clone project'")->fetchColumn();
        $source = $admin->createTask('Source item', ['description' => 'Source description', 'assignees' => ['1']]);
        $admin->post('/?page=edit&id=' . $source, [
            'action' => 'save_task', 'id' => (string)$source, 'title' => 'Source item',
            'description' => 'Source description', 'status_id' => '2', 'parent_id' => '',
            'complexity' => '4', 'project_id' => (string)$projectId,
            'schedule_period' => 'daily', 'assignees' => ['1'],
        ]);
        $admin->post('/?page=task&id=' . $source, ['action' => 'comment', 'task_id' => (string)$source, 'body' => 'Original comment']);

        $page = $admin->get('/?page=new&clone=' . $source);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('value="Source item"', $page->body, 'The title is prefilled.');
        $this->assertStringContainsString('Source description', $page->body, 'The description is prefilled.');
        $this->assertStringContainsString('<option value="2" selected>', $page->body, 'The status is prefilled.');
        $this->assertStringContainsString('name="complexity" id="task-complexity" min="1" max="1000" step="1" value="4"', $page->body, 'The complexity is prefilled.');
        $this->assertStringContainsString('<option value="' . $projectId . '" selected>', $page->body, 'The project is prefilled.');
        $this->assertStringContainsString('<option value="daily" selected>', $page->body, 'The schedule period is prefilled.');
        $this->assertStringContainsString('name="id" value="0"', $page->body, 'The form still creates a new task.');

        // Saving the prefilled form runs the regular create: a brand-new task.
        $saved = $admin->post('/?page=new', [
            'action' => 'save_task', 'id' => '0', 'title' => 'Source item clone',
            'description' => 'Source description', 'status_id' => '2', 'parent_id' => '',
            'complexity' => '4', 'project_id' => (string)$projectId,
            'schedule_period' => 'daily', 'assignees' => ['1'],
        ]);
        $this->assertSame(302, $saved->status);
        if (!preg_match('/page=task&id=(\d+)/', (string)$saved->header('location'), $m)) {
            $this->fail('The clone did not save.');
        }
        $cloneId = (int)$m[1];
        $this->assertNotSame($source, $cloneId, 'The clone is a new task.');
        $clonePage = $admin->taskPage($cloneId);
        $this->assertStringContainsString('Source item clone', $clonePage->body);
        $this->assertStringContainsString('IN PROGRESS', $clonePage->body);
        $this->assertStringContainsString('<strong>Complexity:</strong> 4', $clonePage->body);
        $this->assertStringContainsString('auto-reopen', $clonePage->body, 'The schedule rides along.');
        $this->assertStringContainsString('Task created', $clonePage->body, 'The clone has its own creation history.');
        $this->assertStringNotContainsString('Original comment', $clonePage->body, 'Comments stay with the original task.');

        // The original is untouched.
        $original = $admin->taskPage($source);
        $this->assertStringContainsString('Original comment', $original->body);
        $this->assertSame('4', (string)TestServer::pdo()->query("SELECT complexity FROM tasks WHERE id=$source")->fetchColumn());
    }

    public function test_cloning_an_archived_task_opens_a_fresh_create_form(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Archived source');
        $admin->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId, 'return' => '?']);

        $page = $admin->get('/?page=new&clone=' . $taskId);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('value="Archived source"', $page->body, 'The archived source prefills the create form.');
        $this->assertStringNotContainsString('This task is archived', $page->body, 'The clone is a create, not an edit.');
    }

    public function test_cloning_an_unknown_task_is_a_404(): void
    {
        $admin = $this->adminSession();
        $response = $admin->get('/?page=new&clone=9999');
        $this->assertSame(404, $response->status);
        $this->assertTrue($response->contains('Task not found.'));
    }
}
