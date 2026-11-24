<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

final class ProjectsTest extends E2eTestCase
{
    /** Creates a project through the real form and returns its id. */
    private function createProject(AppSession $session, string $name, array $overrides = []): int
    {
        $session->get('/?page=project_new');
        $response = $session->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => $name,
            'description' => $overrides['description'] ?? 'Created by the test suite',
            'status_id' => $overrides['status_id'] ?? '1',
            'assignees' => (array)($overrides['assignees'] ?? []),
        ]);
        if (!preg_match('/page=project&id=(\d+)/', (string)$response->header('location'), $m)) {
            throw new \RuntimeException('Project creation did not redirect to the project page. Status: ' . $response->status . ' Body: ' . substr($response->body, 0, 600));
        }
        return (int)$m[1];
    }

    /** Creates a task and assigns it to a project in the same request. */
    private function createTaskInProject(AppSession $session, string $title, int $projectId): int
    {
        $session->get('/?page=new');
        $response = $session->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => $title,
            'description' => 'Created by the test suite',
            'status_id' => '1',
            'parent_id' => '',
            'project_id' => (string)$projectId,
            'assignees' => [],
        ]);
        if (!preg_match('/page=task&id=(\d+)/', (string)$response->header('location'), $m)) {
            throw new \RuntimeException('Task creation did not redirect to the task page. Status: ' . $response->status . ' Body: ' . substr($response->body, 0, 600));
        }
        return (int)$m[1];
    }

    public function test_creating_a_project_redirects_to_its_page_and_shows_details(): void
    {
        $admin = $this->adminSession();
        $id = $this->createProject($admin, 'Website relaunch', ['description' => 'New marketing site', 'status_id' => '2', 'assignees' => ['1']]);

        $page = $admin->get('/?page=project&id=' . $id);
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Website relaunch'));
        $this->assertTrue($page->contains('New marketing site'));
        $this->assertTrue($page->contains('IN PROGRESS'));
        $this->assertTrue($page->contains('Administrator')); // assignee display name
        $this->assertTrue($page->contains('Tasks in this project'));

        $row = TestServer::pdo()->query('SELECT name, description, status_id, created_by FROM projects WHERE id=' . $id)->fetch();
        $this->assertSame('Website relaunch', $row['name']);
        $this->assertSame('New marketing site', $row['description']);
        $this->assertSame(2, (int)$row['status_id']);
        $assignees = TestServer::pdo()->query('SELECT COUNT(*) FROM project_assignees WHERE project_id=' . $id)->fetchColumn();
        $this->assertSame('1', (string)$assignees);
    }

    public function test_projects_require_a_name(): void
    {
        $admin = $this->adminSession();
        $admin->get('/?page=project_new');
        $response = $admin->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => '   ',
            'description' => '',
            'status_id' => '1',
            'assignees' => [],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Project name is required.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM projects')->fetchColumn());
    }

    public function test_invalid_status_is_rejected_for_projects(): void
    {
        $admin = $this->adminSession();
        $admin->get('/?page=project_new');
        $response = $admin->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => 'Valid project',
            'description' => '',
            'status_id' => '999',
            'assignees' => [],
        ]);
        $this->assertTrue($response->contains('Choose a valid status.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM projects')->fetchColumn());
    }

    public function test_editing_a_project_updates_its_fields(): void
    {
        $admin = $this->adminSession();
        $id = $this->createProject($admin, 'Original project');

        $editPage = $admin->get('/?page=project_edit&id=' . $id);
        $this->assertSame(200, $editPage->status);
        $this->assertTrue($editPage->contains('Original project'));

        $response = $admin->post('/?page=project_edit&id=' . $id, [
            'action' => 'save_project',
            'id' => (string)$id,
            'name' => 'Renamed project',
            'description' => 'Updated description',
            'status_id' => '2',
            'assignees' => ['1'],
        ]);
        $this->assertSame(302, $response->status);

        $page = $admin->get('/?page=project&id=' . $id);
        $this->assertTrue($page->contains('Renamed project'));
        $this->assertTrue($page->contains('Updated description'));
        $this->assertTrue($page->contains('IN PROGRESS'));
        $this->assertTrue($page->contains('Administrator'));
    }

    public function test_project_pages_return_404_for_unknown_projects(): void
    {
        $admin = $this->adminSession();
        $this->assertSame(404, $admin->get('/?page=project&id=9999')->status);
        $this->assertSame(404, $admin->get('/?page=project_edit&id=9999')->status);
    }

    public function test_tasks_can_be_assigned_to_a_project_and_the_task_list_filters_by_it(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Alpha project');
        $inProject = $this->createTaskInProject($admin, 'Task inside project', $projectId);
        $outside = $admin->createTask('Task outside project');

        // The task list filtered by the project shows only its tasks and names the project.
        $filtered = $admin->get('/?page=tasks&project=' . $projectId);
        $this->assertTrue($filtered->contains('#' . $inProject . ' · Task inside project'));
        $this->assertFalse($filtered->contains('#' . $outside . ' · Task outside project'));
        $this->assertTrue($filtered->contains('Project: Alpha project'));
        $this->assertTrue($filtered->contains('Show all tasks'));

        // The filter combines with the existing filters.
        $combined = $admin->get('/?page=tasks&project=' . $projectId . '&status=1');
        $this->assertTrue($combined->contains('#' . $inProject . ' · Task inside project'));

        $combinedWrongStatus = $admin->get('/?page=tasks&project=' . $projectId . '&status=3');
        $this->assertFalse($combinedWrongStatus->contains('#' . $inProject . ' · Task inside project'));
        $this->assertTrue($combinedWrongStatus->contains('No tasks found.'));

        // The task detail page links back to the project and the edit form preselects it.
        $taskPage = $admin->taskPage($inProject);
        $this->assertTrue($taskPage->contains('Project:'));
        $this->assertTrue($taskPage->contains('page=project&id=' . $projectId));
        $editForm = $admin->get('/?page=edit&id=' . $inProject);
        $this->assertTrue($editForm->contains('value="' . $projectId . '" selected'));
    }

    public function test_tasks_without_a_project_stay_unassigned_when_the_filter_is_active(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Lonely project');
        $outside = $admin->createTask('No project task');

        $filtered = $admin->get('/?page=tasks&project=' . $projectId);
        $this->assertFalse($filtered->contains('#' . $outside . ' · No project task'));
        $this->assertTrue($filtered->contains('No tasks found.'));
    }

    public function test_bulk_move_moves_selected_tasks_to_a_project(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Move target');
        $first = $admin->createTask('Move one');
        $second = $admin->createTask('Move two');
        $staying = $admin->createTask('Stays outside');

        // The task list offers the bulk move control next to the bulk archive button.
        $list = $admin->get('/?');
        $this->assertTrue($list->contains('id="bulk-move-btn"'));
        $this->assertTrue($list->contains('value="bulk_move_tasks"'));
        $this->assertTrue($list->contains('id="bulk-move-project"'));
        $this->assertTrue($list->contains('value="bulk_archive_tasks"'));

        $response = $admin->post('/?', [
            'action' => 'bulk_move_tasks',
            'return' => '?',
            'project_id' => (string)$projectId,
            'task_ids' => [(string)$first, (string)$second],
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?', (string)$response->header('location'));

        $after = $admin->get('/?');
        $this->assertTrue($after->contains('2 tasks moved to project Move target.'));

        $moved = [];
        foreach (TestServer::pdo()->query('SELECT id, project_id FROM tasks')->fetchAll() as $row) {
            $moved[(int)$row['id']] = (int)$row['project_id'];
        }
        $this->assertSame($projectId, $moved[$first]);
        $this->assertSame($projectId, $moved[$second]);
        $this->assertSame(0, $moved[$staying]);

        // Every moved task gets a history entry recording the move.
        $this->assertSame(2, (int)TestServer::pdo()->query(
            "SELECT COUNT(*) FROM task_history WHERE event='Task moved to project'"
        )->fetchColumn());

        // Clear errors instead of silent no-ops for missing selection or project.
        $noSelection = $admin->post('/?', [
            'action' => 'bulk_move_tasks',
            'return' => '?',
            'project_id' => (string)$projectId,
            'task_ids' => [],
        ]);
        $this->assertTrue($noSelection->contains('Select at least one task to move.'));

        $badProject = $admin->post('/?', [
            'action' => 'bulk_move_tasks',
            'return' => '?',
            'project_id' => '9999',
            'task_ids' => [(string)$first],
        ]);
        $this->assertTrue($badProject->contains('Choose a valid project.'));
    }

    public function test_projects_list_shows_projects_with_counts_and_a_tasks_button(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Listed project');
        $this->createTaskInProject($admin, 'Counted task', $projectId);
        $other = $this->createProject($admin, 'Empty project');

        $list = $admin->get('/?page=projects');
        $this->assertTrue($list->contains('#' . $projectId . ' · Listed project'));
        $this->assertTrue($list->contains('#' . $other . ' · Empty project'));
        $this->assertTrue($list->contains('page=project&id=' . $projectId));
        // [Tasks...] button per project row leading to the filtered task list
        // (the v2 task list since #57).
        $this->assertTrue($list->contains('page=tasks&project=' . $projectId));
        $this->assertTrue($list->contains('Tasks…'));
        $this->assertTrue($list->contains('1 task'));
        $this->assertTrue($list->contains('+ New project'));
        $this->assertTrue($list->contains('page=project_edit&id=' . $projectId));

        // Following the Tasks button shows the filtered list.
        $tasks = $admin->get('/?page=tasks&project=' . $projectId);
        $this->assertTrue($tasks->contains('Counted task'));
    }

    public function test_projects_list_renders_assignee_names_per_row(): void
    {
        $admin = $this->adminSession();
        $staffed = $this->createProject($admin, 'Staffed project', ['assignees' => ['1']]);
        $unstaffed = $this->createProject($admin, 'Unstaffed project', ['assignees' => []]);

        $list = $admin->get('/?page=projects');
        // The names are fetched with one grouped query for the whole page and
        // must still land in the row of exactly their own project. Since #30 v2
        // the renderer puts the activity dot before the name; the admin session
        // has just requested pages, so its dot is green.
        $this->assertTrue($list->contains(
            '#' . $staffed . ' · Staffed project</a></td><td><span class="badge text-bg-light border">TODO</span></td><td><span class="text-nowrap"><span class="tb-activity-dot tb-activity-green" title="Active in the last 15 minutes"></span>Administrator</span></td>'
        ));
        $this->assertTrue($list->contains(
            '#' . $unstaffed . ' · Unstaffed project</a></td><td><span class="badge text-bg-light border">TODO</span></td><td>—</td>'
        ));
    }

    public function test_the_projects_list_supports_sorting(): void
    {
        $admin = $this->adminSession();
        $beta = $this->createProject($admin, 'Beta project');
        $alpha = $this->createProject($admin, 'Alpha project');

        $byNameAsc = $admin->get('/?page=projects&sort=name&dir=asc');
        $this->assertTrue($byNameAsc->contains('aria-sort="ascending"'));
        $this->assertTrue($byNameAsc->contains('page=project&id=' . $alpha));

        $byNameDesc = $admin->get('/?page=projects&sort=name&dir=desc');
        $this->assertTrue($byNameDesc->contains('aria-sort="descending"'));
        $this->assertTrue($byNameDesc->contains('page=project&id=' . $beta));

        // Task counts order: Beta has no tasks, Alpha has none either here; give Beta one.
        $this->createTaskInProject($admin, 'One task', $beta);
        $byTasks = $admin->get('/?page=projects&sort=tasks&dir=desc');
        $this->assertTrue($byTasks->contains('sort=tasks&dir=asc') || $byTasks->contains('sort=tasks&amp;dir=asc'));

        // Unknown parameters fall back to the default (updated desc) without errors.
        $fallback = $admin->get('/?page=projects&sort=evil&dir=DROP');
        $this->assertSame(200, $fallback->status);
        $this->assertTrue($fallback->contains('page=project&id=' . $beta));
    }

    public function test_deleting_a_project_keeps_its_tasks_and_clears_the_assignment(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Doomed project');
        $taskId = $this->createTaskInProject($admin, 'Surviving task', $projectId);

        $delete = $admin->post('/?page=project&id=' . $projectId, ['action' => 'delete_project', 'id' => (string)$projectId, 'return' => '?page=projects']);
        $this->assertSame(302, $delete->status);
        $this->assertSame('?page=projects', (string)$delete->header('location'));

        $after = $admin->get('/?page=projects');
        $this->assertTrue($after->contains('Project deleted.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        $this->assertSame(404, $admin->get('/?page=project&id=' . $projectId)->status);

        // The task itself survives and loses its project reference.
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $taskId)->fetchColumn());
        $row = TestServer::pdo()->query('SELECT project_id FROM tasks WHERE id=' . $taskId)->fetch();
        $this->assertNull($row['project_id']);
        $taskPage = $admin->taskPage($taskId);
        $this->assertFalse($taskPage->contains('Project:'));
    }

    public function test_only_administrators_or_the_creator_can_delete_a_project(): void
    {
        $admin = $this->adminSession();
        $ownProject = $this->createProject($admin, 'Admin project');
        TestServer::pdo()->exec('UPDATE projects SET created_by=1 WHERE id=' . $ownProject);

        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'dave',
            'display_name' => 'Dave User',
            'password' => 'DavePass12345',
            'role' => 'user',
        ]);
        $dave = new AppSession('dave', 'DavePass12345');

        $denied = $dave->post('/?page=project&id=' . $ownProject, ['action' => 'delete_project', 'id' => (string)$ownProject]);
        $this->assertSame(200, $denied->status);
        $this->assertTrue($denied->contains('Only an administrator or project creator can delete this project.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM projects WHERE id=' . $ownProject)->fetchColumn());

        $davesProject = $this->createProject($dave, 'Daves own project');
        $allowed = $dave->post('/?page=project&id=' . $davesProject, ['action' => 'delete_project', 'id' => (string)$davesProject]);
        $this->assertSame(302, $allowed->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM projects WHERE id=' . $davesProject)->fetchColumn());
    }

    public function test_bulk_delete_projects_respects_permissions(): void
    {
        $admin = $this->adminSession();
        $first = $this->createProject($admin, 'Bulk one');
        $second = $this->createProject($admin, 'Bulk two');
        TestServer::pdo()->exec('UPDATE projects SET created_by=1 WHERE id IN (' . $first . ',' . $second . ')');

        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'erin',
            'display_name' => 'Erin User',
            'password' => 'ErinPass123456',
            'role' => 'user',
        ]);
        $erin = new AppSession('erin', 'ErinPass123456');
        $erins = $this->createProject($erin, 'Erins project');

        // A regular user bulk-deleting: her own project goes, the admin's are skipped.
        $response = $erin->post('/?page=projects', [
            'action' => 'bulk_delete_projects',
            'project_ids' => [(string)$first, (string)$second, (string)$erins],
            'return' => '?page=projects',
        ]);
        $this->assertSame(302, $response->status);
        $list = $erin->get('/?page=projects');
        $this->assertTrue($list->contains('1 project deleted. 2 skipped: only administrators or the project creator can delete a project.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM projects WHERE id=' . $first)->fetchColumn());
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM projects WHERE id=' . $erins)->fetchColumn());
    }

    public function test_empty_bulk_delete_is_rejected(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=projects', ['action' => 'bulk_delete_projects', 'project_ids' => []]);
        $this->assertTrue($response->contains('Select at least one project to delete.'));
    }

    public function test_navigation_links_to_projects_and_the_task_form_offers_a_project(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Nav project');

        $home = $admin->get('/?');
        $this->assertTrue($home->contains('page=projects">Projects</a>'));  // #81: the navbar entry opens the projects page.

        $form = $admin->get('/?page=new');
        $this->assertTrue($form->contains('<label class="form-label" for="task-project">Project</label>'));
        $this->assertTrue($form->contains('#' . $projectId . ' - Nav project'));

        $projectForm = $admin->get('/?page=project_new');
        $this->assertTrue($projectForm->contains('Create project'));
    }

    public function test_assigning_an_unknown_project_to_a_task_is_rejected(): void
    {
        $admin = $this->adminSession();
        $admin->get('/?page=new');
        $response = $admin->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => 'Orphan task',
            'description' => '',
            'status_id' => '1',
            'parent_id' => '',
            'project_id' => '9999',
            'assignees' => [],
        ]);
        $this->assertTrue($response->contains('Invalid project.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
    }

    public function test_task_list_renders_no_raw_php_and_the_bulk_archive_form_still_works(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Regression project');
        $taskId = $this->createTaskInProject($admin, 'Bulk regression task', $projectId);

        $page = $admin->get('/?page=tasks&project=' . $projectId);
        $this->assertFalse($page->contains('<?php'));
        $this->assertFalse($page->contains('http_build_query'));
        $this->assertFalse($page->contains('$qs['));
        // The bulk archive form must be intact on the filtered task list (#12 moved
        // the task list bulk action from delete to archive - keep covering it here).
        $this->assertTrue($page->contains('id="bulk-form"'));
        $this->assertTrue($page->contains('value="bulk_archive_tasks"'));

        $response = $admin->post('/?page=tasks&project=' . $projectId, [
            'action' => 'bulk_archive_tasks',
            'task_ids' => [(string)$taskId],
            'return' => '?page=tasks&project=' . $projectId,
        ]);
        $this->assertSame(302, $response->status);
        // Archiving keeps the task row but hides it from the task list.
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM tasks WHERE id=' . $taskId)->fetchColumn());
        $this->assertNotNull(TestServer::pdo()->query('SELECT archived_at FROM tasks WHERE id=' . $taskId)->fetchColumn());
        $list = $admin->get('/?page=tasks&project=' . $projectId);
        $this->assertTrue($list->contains('1 task archived.'));
        $this->assertTrue($list->contains('No tasks found.'));
        $this->assertTrue($list->contains('Project: Regression project'));
    }
}
