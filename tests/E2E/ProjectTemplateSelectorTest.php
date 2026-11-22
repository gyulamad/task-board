<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * #20 - Project template selector on the project create page (parent #10).
 *
 * Mirrors the task create page prefill: the project create form offers the
 * project templates (and only those) in a Template selector; picking one
 * fills the description textarea client-side from the data-description
 * attribute. The project edit form intentionally has no selector.
 *
 * These tests need the templates infrastructure (#19): the templates table
 * and the template CRUD used to seed fixtures.
 */
final class ProjectTemplateSelectorTest extends E2eTestCase
{
    /** Creates a template of the given type through the real form and returns its id. */
    private function createTemplate(AppSession $session, string $name, string $type, string $description): int
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

    /** Creates a project through the real form and returns its id. */
    private function createProject(AppSession $session, string $name, string $description): int
    {
        $session->get('/?page=project_new');
        $response = $session->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => $name,
            'description' => $description,
            'status_id' => '1',
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status, 'Project creation should redirect to the project page.');
        $row = TestServer::pdo()->prepare('SELECT id FROM projects WHERE name=?');
        $row->execute([$name]);
        $id = $row->fetchColumn();
        $this->assertNotFalse($id, 'The project should have been stored in the database.');
        return (int)$id;
    }

    public function test_project_create_page_offers_project_templates_with_prefill_data(): void
    {
        $admin = $this->adminSession();
        $this->createTemplate($admin, 'Website project', 'project', "Goal:\n1. Launch the site\n2. Collect feedback");
        $this->createTemplate($admin, 'Bug report', 'task', 'Steps to reproduce the bug.');

        $newPage = $admin->get('/?page=project_new');
        $this->assertSame(200, $newPage->status);

        // The project create form offers the project template and carries its description.
        $this->assertTrue($newPage->contains('id="project-template"'));
        $this->assertTrue($newPage->contains('Website project'));
        $this->assertTrue($newPage->contains('data-description="Goal:' . "\n" . '1. Launch the site' . "\n" . '2. Collect feedback"'));

        // Task templates must not be offered on the project create form.
        $this->assertFalse($newPage->contains('Bug report'));
    }

    public function test_project_edit_page_has_no_template_selector(): void
    {
        $admin = $this->adminSession();
        $this->createTemplate($admin, 'Website project', 'project', 'Project description for a website.');
        $projectId = $this->createProject($admin, 'Existing project', 'Created by the test suite');

        $editPage = $admin->get('/?page=project_edit&id=' . $projectId);
        $this->assertSame(200, $editPage->status);
        $this->assertFalse($editPage->contains('id="project-template"'));
    }

    public function test_project_create_page_has_no_selector_when_no_project_templates_exist(): void
    {
        $admin = $this->adminSession();
        // A task template alone must not produce a selector on the project form.
        $this->createTemplate($admin, 'Bug report', 'task', 'Steps to reproduce the bug.');

        $newPage = $admin->get('/?page=project_new');
        $this->assertSame(200, $newPage->status);
        $this->assertFalse($newPage->contains('id="project-template"'));
    }
}
