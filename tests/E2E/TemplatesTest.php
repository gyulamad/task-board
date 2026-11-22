<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

final class TemplatesTest extends E2eTestCase
{
    private function createTemplate(AppSession $session, string $name, string $type = 'task', string $description = 'Template body text'): int
    {
        $session->get('/?page=template_new');
        $response = $session->post('/?page=template_new', [
            'action' => 'save_template',
            'id' => '0',
            'type' => $type,
            'name' => $name,
            'description' => $description,
        ]);
        $this->assertSame(302, $response->status, 'Template creation should redirect to the templates list.');
        $row = TestServer::pdo()->prepare('SELECT id FROM templates WHERE name=?');
        $row->execute([$name]);
        $id = $row->fetchColumn();
        $this->assertNotFalse($id, 'The template should have been stored in the database.');
        return (int)$id;
    }

    public function test_template_crud_lifecycle(): void
    {
        $admin = $this->adminSession();

        // Create.
        $id = $this->createTemplate($admin, 'Bug report', 'task', 'Steps to reproduce:' . "\n" . '1. Open the page');
        $list = $admin->get('/?page=templates');
        $this->assertTrue($list->contains('Bug report'));
        $this->assertTrue($list->contains('Steps to reproduce:'));
        $this->assertTrue($list->contains('Template created.'));

        // Edit.
        $editPage = $admin->get('/?page=template_edit&id=' . $id);
        $this->assertSame(200, $editPage->status);
        $this->assertTrue($editPage->contains('Bug report'));
        $response = $admin->post('/?page=template_edit&id=' . $id, [
            'action' => 'save_template',
            'id' => (string)$id,
            'type' => 'task',
            'name' => 'Bug report v2',
            'description' => 'Updated steps to reproduce.',
        ]);
        $this->assertSame(302, $response->status);
        $row = TestServer::pdo()->query('SELECT name, description FROM templates WHERE id=' . $id)->fetch();
        $this->assertSame('Bug report v2', $row['name']);
        $this->assertSame('Updated steps to reproduce.', $row['description']);
        $this->assertTrue($admin->get('/?page=templates')->contains('Template updated.'));

        // Delete.
        $delete = $admin->post('/?page=templates', ['action' => 'delete_template', 'id' => (string)$id]);
        $this->assertSame(302, $delete->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM templates WHERE id=' . $id)->fetchColumn());
        $this->assertTrue($admin->get('/?page=templates')->contains('Template deleted.'));
    }

    public function test_template_requires_a_name(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=template_new', [
            'action' => 'save_template',
            'id' => '0',
            'type' => 'task',
            'name' => '   ',
            'description' => '',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Template name is required.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM templates')->fetchColumn());
    }

    public function test_invalid_template_type_is_rejected(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=template_new', [
            'action' => 'save_template',
            'id' => '0',
            'type' => 'bogus',
            'name' => 'Valid name',
            'description' => '',
        ]);
        $this->assertTrue($response->contains('Choose a valid template type.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM templates')->fetchColumn());
    }

    public function test_task_create_page_shows_task_templates_and_prefill_data(): void
    {
        $admin = $this->adminSession();
        $this->createTemplate($admin, 'Bug report', 'task', 'Steps to reproduce the bug.');
        $this->createTemplate($admin, 'Website project', 'project', 'Project description for a website.');

        // The task create form offers the task template and carries its description.
        $newPage = $admin->get('/?page=new');
        $this->assertTrue($newPage->contains('id="task-template"'));
        $this->assertTrue($newPage->contains('Bug report'));
        $this->assertTrue($newPage->contains('data-description="Steps to reproduce the bug."'));

        // Project templates must not be offered on the task create form.
        $this->assertFalse($newPage->contains('Website project'));

        // The task edit form has no template selector.
        $taskId = $admin->createTask('Existing task');
        $editPage = $admin->get('/?page=edit&id=' . $taskId);
        $this->assertFalse($editPage->contains('id="task-template"'));
    }

    public function test_templates_list_can_be_filtered_by_type(): void
    {
        $admin = $this->adminSession();
        $this->createTemplate($admin, 'Bug report', 'task');
        $this->createTemplate($admin, 'Website project', 'project');

        $projectList = $admin->get('/?page=templates&type=project');
        $this->assertTrue($projectList->contains('Website project'));
        $this->assertFalse($projectList->contains('Bug report'));

        $taskList = $admin->get('/?page=templates&type=task');
        $this->assertTrue($taskList->contains('Bug report'));
        $this->assertFalse($taskList->contains('Website project'));
    }

    public function test_only_admin_or_creator_can_delete_a_template(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'carol',
            'display_name' => 'Carol User',
            'password' => 'CarolPass12345',
            'role' => 'user',
        ]);
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'dave',
            'display_name' => 'Dave User',
            'password' => 'DavePass123456',
            'role' => 'user',
        ]);
        $carol = new AppSession('carol', 'CarolPass12345');
        $dave = new AppSession('dave', 'DavePass123456');

        $templateId = $this->createTemplate($carol, 'Carols template', 'task');

        // Dave is neither admin nor creator: deletion is rejected.
        $denied = $dave->post('/?page=templates', ['action' => 'delete_template', 'id' => (string)$templateId]);
        $this->assertSame(200, $denied->status);
        $this->assertTrue($denied->contains('Only an administrator or template creator can delete this template.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM templates WHERE id=' . $templateId)->fetchColumn());

        // The creator can delete her own template.
        $allowed = $carol->post('/?page=templates', ['action' => 'delete_template', 'id' => (string)$templateId]);
        $this->assertSame(302, $allowed->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM templates WHERE id=' . $templateId)->fetchColumn());

        // An administrator can delete a template created by somebody else.
        $otherId = $this->createTemplate($carol, 'Carols other template', 'task');
        $adminDelete = $admin->post('/?page=templates', ['action' => 'delete_template', 'id' => (string)$otherId]);
        $this->assertSame(302, $adminDelete->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM templates WHERE id=' . $otherId)->fetchColumn());
    }
}
