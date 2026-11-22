<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #25: the CRUD list filter dropdowns accept several values at once.
 * The task list dropdowns (assignee, status, parent, project) and the
 * templates type dropdown submit arrays (?assignee[]=2&assignee[]=5);
 * values inside one dropdown match any of them (OR), different dropdowns
 * combine (AND). Legacy scalar URLs (?assignee=5) keep working.
 */
final class MultiSelectFiltersTest extends E2eTestCase
{
    private int $adminId;
    private int $daveId;
    private int $eveId;
    private int $alpha;   // assigned to admin, status TODO
    private int $beta;    // assigned to Dave, status IN PROGRESS
    private int $gamma;   // assigned to Eve, status DONE
    private int $delta;   // unassigned, status TODO

    private function seed(): void
    {
        $this->admin = $this->adminSession();
        $this->createUser($this->admin, 'dave', 'Dave Multi', 'DavePass12345');
        $this->createUser($this->admin, 'eve', 'Eve Multi', 'EvePass12345');
        $this->adminId = $this->userId('admin');
        $this->daveId = $this->userId('dave');
        $this->eveId = $this->userId('eve');

        $this->alpha = $this->admin->createTask('Alpha multi', ['status_id' => '1', 'assignees' => [(string)$this->adminId]]);
        $this->beta = $this->admin->createTask('Beta multi', ['status_id' => '2', 'assignees' => [(string)$this->daveId]]);
        $this->gamma = $this->admin->createTask('Gamma multi', ['status_id' => '3', 'assignees' => [(string)$this->eveId]]);
        $this->delta = $this->admin->createTask('Delta multi', ['status_id' => '1', 'assignees' => []]);
    }

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

    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'User ' . $username . ' does not exist.');
        return (int)$id;
    }

    /** Creates a project through the real form and returns its id. */
    private function createProject(string $name): int
    {
        $this->admin->get('/?page=project_new');
        $response = $this->admin->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => $name,
            'description' => 'Created by the test suite',
            'status_id' => '1',
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);
        if (!preg_match('/page=project&id=(\d+)/', (string)$response->header('location'), $m)) {
            $this->fail('Project creation did not redirect to the project page.');
        }
        return (int)$m[1];
    }

    /** Creates a task that belongs to a project and returns its id. */
    private function createTaskInProject(string $title, int $projectId, array $overrides = []): int
    {
        $this->admin->get('/?page=new');
        $response = $this->admin->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => $title,
            'description' => 'Created by the test suite',
            'status_id' => $overrides['status_id'] ?? '1',
            'parent_id' => $overrides['parent_id'] ?? '',
            'project_id' => (string)$projectId,
            'assignees' => (array)($overrides['assignees'] ?? []),
        ]);
        if (!preg_match('/page=task&id=(\d+)/', (string)$response->header('location'), $m)) {
            $this->fail('Task creation did not redirect to the task page.');
        }
        return (int)$m[1];
    }

    public function test_assignee_multiselect_matches_any_selected_user(): void
    {
        $this->seed();

        $both = $this->admin->get('/?page=tasks&assignee[]=' . $this->daveId . '&assignee[]=' . $this->eveId);
        $this->assertTrue($both->contains('#' . $this->beta . ' · Beta multi'));
        $this->assertTrue($both->contains('#' . $this->gamma . ' · Gamma multi'));
        $this->assertFalse($both->contains('#' . $this->alpha . ' · Alpha multi'), 'The admin task is outside the selected assignees.');
        $this->assertFalse($both->contains('#' . $this->delta . ' · Delta multi'));

        $single = $this->admin->get('/?page=tasks&assignee[]=' . $this->eveId);
        $this->assertTrue($single->contains('#' . $this->gamma . ' · Gamma multi'));
        $this->assertFalse($single->contains('#' . $this->beta . ' · Beta multi'));
    }

    public function test_unassigned_keyword_combines_with_user_ids_in_one_dropdown(): void
    {
        $this->seed();

        $mixed = $this->admin->get('/?page=tasks&assignee[]=unassigned&assignee[]=' . $this->daveId);
        $this->assertTrue($mixed->contains('#' . $this->delta . ' · Delta multi'), 'unassigned matches tasks without assignees');
        $this->assertTrue($mixed->contains('#' . $this->beta . ' · Beta multi'));
        $this->assertFalse($mixed->contains('#' . $this->alpha . ' · Alpha multi'));
        $this->assertFalse($mixed->contains('#' . $this->gamma . ' · Gamma multi'));
    }

    public function test_status_multiselect_matches_any_selected_status(): void
    {
        $this->seed();

        $two = $this->admin->get('/?page=tasks&status[]=1&status[]=2');
        $this->assertTrue($two->contains('#' . $this->alpha . ' · Alpha multi'));
        $this->assertTrue($two->contains('#' . $this->beta . ' · Beta multi'));
        $this->assertFalse($two->contains('#' . $this->gamma . ' · Gamma multi'), 'DONE tasks are outside statuses 1 and 2');

        $legacy = $this->admin->get('/?page=tasks&status=2');
        $this->assertTrue($legacy->contains('#' . $this->beta . ' · Beta multi'));
        $this->assertFalse($legacy->contains('#' . $this->alpha . ' · Alpha multi'));
    }

    public function test_parent_multiselect_accepts_ids_and_the_none_keyword(): void
    {
        $this->seed();
        $parentOne = $this->admin->createTask('Multi parent one', ['status_id' => '1']);
        $childOne = $this->admin->createTask('Multi child one', ['status_id' => '1', 'parent_id' => (string)$parentOne]);
        $parentTwo = $this->admin->createTask('Multi parent two', ['status_id' => '1']);
        $childTwo = $this->admin->createTask('Multi child two', ['status_id' => '1', 'parent_id' => (string)$parentTwo]);

        $both = $this->admin->get('/?page=tasks&parent[]=none&parent[]=' . $parentOne);
        $this->assertTrue($both->contains('#' . $parentOne . ' · Multi parent one'));
        $this->assertTrue($both->contains('#' . $childOne . ' · Multi child one'));
        $this->assertFalse($both->contains('#' . $childTwo . ' · Multi child two'), 'Children of a different parent are outside the filter');

        $noneOnly = $this->admin->get('/?page=tasks&parent[]=none');
        $this->assertTrue($noneOnly->contains('#' . $parentOne . ' · Multi parent one'));
        $this->assertFalse($noneOnly->contains('#' . $childOne . ' · Multi child one'));

        $idOnly = $this->admin->get('/?page=tasks&parent[]=' . $parentOne);
        $this->assertTrue($idOnly->contains('#' . $childOne . ' · Multi child one'));
        // parentOne's own title also appears in the parent filter dropdown
        // options, so absence is asserted on a child title only.
        $this->assertFalse($idOnly->contains('#' . $childTwo . ' · Multi child two'));
    }

    public function test_project_multiselect_matches_any_selected_project(): void
    {
        $this->seed();
        $projectOne = $this->createProject('Multi project one');
        $projectTwo = $this->createProject('Multi project two');
        $inOne = $this->createTaskInProject('Multi task in one', $projectOne);
        $inTwo = $this->createTaskInProject('Multi task in two', $projectTwo);

        $both = $this->admin->get('/?page=tasks&project[]=' . $projectOne . '&project[]=' . $projectTwo);
        $this->assertTrue($both->contains('#' . $inOne . ' · Multi task in one'));
        $this->assertTrue($both->contains('#' . $inTwo . ' · Multi task in two'));
        $this->assertFalse($both->contains('#' . $this->alpha . ' · Alpha multi'), 'Tasks without a project are outside the filter');

        // The header badge lists every selected project and the "Show all
        // tasks" link clears the project filter entirely.
        $this->assertTrue($both->contains('Projects: Multi project one, Multi project two'));
        $this->assertTrue($both->contains('Show all tasks'));

        $legacy = $this->admin->get('/?page=tasks&project=' . $projectOne);
        $this->assertTrue($legacy->contains('#' . $inOne . ' · Multi task in one'));
        $this->assertFalse($legacy->contains('#' . $inTwo . ' · Multi task in two'));
        $this->assertTrue($legacy->contains('Project: Multi project one'));
    }

    public function test_filters_from_different_dropdowns_combine_with_and(): void
    {
        $this->seed();

        $combined = $this->admin->get('/?page=tasks&assignee[]=' . $this->adminId . '&status[]=1');
        $this->assertTrue($combined->contains('#' . $this->alpha . ' · Alpha multi'));
        $this->assertFalse($combined->contains('#' . $this->beta . ' · Beta multi'), 'Dave is assigned but the status does not match');
        $this->assertFalse($combined->contains('#' . $this->delta . ' · Delta multi'), 'Status matches but nobody is assigned');
    }

    public function test_filter_form_renders_multi_selects_and_marks_chosen_options(): void
    {
        $this->seed();

        $page = $this->admin->get('/?page=tasks&assignee[]=' . $this->daveId . '&status[]=1&parent[]=none');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('name="assignee[]" multiple'));
        $this->assertTrue($page->contains('name="status[]" multiple'));
        $this->assertTrue($page->contains('name="parent[]" multiple'));
        $this->assertTrue($page->contains('name="project[]" multiple'));
        $this->assertTrue($page->contains('<option value="' . $this->daveId . '" selected'));
        $this->assertTrue($page->contains('<option value="1" selected'));
        $this->assertTrue($page->contains('<option value="none" selected'));
        $this->assertTrue($page->contains('<option value="unassigned"'), 'The #21 unassigned option stays available');
    }

    public function test_sort_links_and_bulk_form_keep_every_selected_filter_value(): void
    {
        $this->seed();

        $page = $this->admin->get('/?page=tasks&assignee[]=' . $this->daveId . '&assignee[]=' . $this->eveId . '&status[]=1');
        $this->assertSame(200, $page->status);
        // Sort headers are built by listUrl(): the arrays must re-appear in
        // the generated links (assignee[0]=2&assignee[1]=3 style encoding).
        $this->assertTrue($page->contains('assignee%5B0%5D=' . $this->daveId));
        $this->assertTrue($page->contains('assignee%5B1%5D=' . $this->eveId));
        $this->assertTrue($page->contains('status%5B0%5D=1'));
        // The bulk action form mirrors the filters as hidden inputs so the
        // return URL can be rebuilt with all values.
        $this->assertTrue($page->contains('<input type="hidden" name="assignee[]" value="' . $this->daveId . '">'));
        $this->assertTrue($page->contains('<input type="hidden" name="assignee[]" value="' . $this->eveId . '">'));
        $this->assertTrue($page->contains('<input type="hidden" name="status[]" value="1">'));
    }

    public function test_templates_type_filter_accepts_multiple_types(): void
    {
        $this->admin = $this->adminSession();
        $this->createTemplate('Multi task template', 'task');
        $this->createTemplate('Multi project template', 'project');

        $both = $this->admin->get('/?page=templates&type[]=task&type[]=project');
        $this->assertTrue($both->contains('Multi task template'));
        $this->assertTrue($both->contains('Multi project template'));

        $taskOnly = $this->admin->get('/?page=templates&type[]=task');
        $this->assertTrue($taskOnly->contains('Multi task template'));
        $this->assertFalse($taskOnly->contains('Multi project template'));

        $legacy = $this->admin->get('/?page=templates&type=project');
        $this->assertTrue($legacy->contains('Multi project template'));
        $this->assertFalse($legacy->contains('Multi task template'));
    }

    public function test_hand_crafted_filter_values_cannot_inject_sql_and_are_ignored(): void
    {
        $this->seed();

        // The hostile status value is not a valid id, so it is dropped; the
        // assignee filter from the same request must still apply.
        $hostile = $this->admin->get('/?page=tasks&status[]=1%29%20OR%201%3D1--&assignee[]=' . $this->daveId);
        $this->assertSame(200, $hostile->status, 'The list must render, not fatal, on hostile filter values.');
        $this->assertTrue($hostile->contains('#' . $this->beta . ' · Beta multi'), 'The valid assignee filter still applies');
        $this->assertFalse($hostile->contains('#' . $this->alpha . ' · Alpha multi'));
        $this->assertFalse($hostile->contains('#' . $this->delta . ' · Delta multi'), 'The whole list must not leak: the assignee filter stays active');
    }

    private function createTemplate(string $name, string $type): void
    {
        $this->admin->get('/?page=template_new');
        $response = $this->admin->post('/?page=template_new', [
            'action' => 'save_template',
            'id' => '0',
            'type' => $type,
            'name' => $name,
            'description' => 'Created by the test suite',
        ]);
        $this->assertSame(302, $response->status);
    }
}
