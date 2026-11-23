<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;

/**
 * Task #47: saving a NEW task with no Project selected pops a confirmation
 * box before the task is stored. The guard is the same inline confirm pattern
 * the board already uses for its destructive actions, so it lives on the
 * client: the server keeps accepting project-less saves (that is what the
 * "OK" button means), and Cancel simply keeps the operator on the form. The
 * edit form keeps saving without a dialog - there the stored project is
 * visible on the form and unsetting it is a deliberate change.
 */
final class TaskCreateProjectConfirmationTest extends E2eTestCase
{
    public function test_the_create_form_carries_the_project_confirmation(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=new');
        $this->assertSame(200, $page->status);

        // the form submits through the guard and the page ships the helper behind it
        $this->assertTrue($page->contains('onsubmit="return tb_confirm_no_project();"'));
        $this->assertTrue($page->contains('function tb_confirm_no_project()'));
        // the dialog says exactly what is about to happen
        $this->assertTrue($page->contains('No Project is selected. Create this task without a project?'));
        // the helper reads the project select through its dedicated id
        $this->assertTrue($page->contains('<select class="form-select" name="project_id" id="task-project">'));
        $this->assertTrue($page->contains('<label class="form-label" for="task-project">Project</label>'));
    }

    public function test_the_edit_form_keeps_saving_without_a_dialog(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Guard lives on the create form only');

        $edit = $admin->get('/?page=edit&id=' . $id);
        $this->assertSame(200, $edit->status);
        $this->assertFalse($edit->contains('tb_confirm_no_project'), 'the edit page renders no confirmation guard');
        // the select still gets the id (shared form markup), just without a guard around it
        $this->assertTrue($edit->contains('name="project_id" id="task-project"'));
    }

    public function test_confirming_the_dialog_still_creates_the_task_without_a_project(): void
    {
        // Pressing OK lets the POST go out unchanged, so the server must store
        // the project-less task exactly as before #47 existed.
        $admin = $this->adminSession();
        $response = $admin->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => 'Created after confirming the empty project',
            'description' => 'Saved with the confirm box answered OK',
            'status_id' => '1',
            'parent_id' => '',
            'project_id' => '',
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertMatchesRegularExpression('/page=task&id=(\d+)/', (string)$response->header('location'));
        preg_match('/page=task&id=(\d+)/', (string)$response->header('location'), $m);

        $task = $admin->taskPage((int)$m[1]);
        $this->assertSame(200, $task->status);
        $this->assertTrue($task->contains('Created after confirming the empty project'));
        $this->assertFalse($task->contains('<strong>Project:</strong>'), 'the stored task belongs to no project');
    }

    public function test_creating_with_a_project_selected_stays_the_happy_path(): void
    {
        // A picked project never triggers the dialog (the helper returns early),
        // so the classic create-into-a-project flow must behave exactly as before.
        $admin = $this->adminSession();

        $admin->get('/?page=project_new');
        $projectResponse = $admin->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => 'Confirmed project',
            'description' => 'Created by the test suite',
            'status_id' => '1',
            'assignees' => [],
        ]);
        $this->assertSame(302, $projectResponse->status);
        preg_match('/page=project&id=(\d+)/', (string)$projectResponse->header('location'), $pm);
        $projectId = (int)$pm[1];

        $response = $admin->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => 'Created into a picked project',
            'description' => 'No dialog on this path',
            'status_id' => '1',
            'parent_id' => '',
            'project_id' => (string)$projectId,
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);
        preg_match('/page=task&id=(\d+)/', (string)$response->header('location'), $m);

        $task = $admin->taskPage((int)$m[1]);
        $this->assertTrue($task->contains('<strong>Project:</strong> <a href="?page=project&id=' . $projectId . '">Confirmed project</a>'));
    }
}
