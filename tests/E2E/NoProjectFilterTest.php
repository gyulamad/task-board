<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #28: the Projects filter of the task list gains a "No project"
 * option that shows the tasks without a project, combinable with the
 * concrete project ids like every other multi-select filter.
 */
final class NoProjectFilterTest extends E2eTestCase
{
    /** Creates a project through the real form and returns its id. */
    private function createProject(AppSession $admin, string $name): int
    {
        $response = $admin->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => $name,
            'description' => 'Project for the no-project filter test',
            'status_id' => '1',
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);
        $row = TestServer::pdo()->prepare('SELECT id FROM projects WHERE name=?');
        $row->execute([$name]);
        $id = $row->fetchColumn();
        $this->assertNotFalse($id);
        return (int)$id;
    }

    /** Seeds two tasks inside $projectId and two without any project. */
    private function seedTasks(AppSession $admin, int $projectId): array
    {
        $inProject1 = $admin->createTask('Inside project one');
        $inProject2 = $admin->createTask('Inside project two');
        foreach ([$inProject1, $inProject2] as $taskId) {
            $admin->post('/?page=edit&id=' . $taskId, [
                'action' => 'save_task',
                'id' => (string)$taskId,
                'title' => 'Inside project',
                'description' => 'Created by the test suite',
                'status_id' => '1',
                'parent_id' => '',
                'project_id' => (string)$projectId,
                'assignees' => [],
            ]);
        }
        $outside1 = $admin->createTask('Outside alpha');
        $outside2 = $admin->createTask('Outside beta');
        return [$inProject1, $inProject2, $outside1, $outside2];
    }

    public function test_no_project_filter_shows_only_tasks_without_a_project(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Apollo');
        [, , $outside1, $outside2] = $this->seedTasks($admin, $projectId);

        $filtered = $admin->get('/?page=tasks&project=none');
        $this->assertTrue($filtered->contains('#' . $outside1 . ' · Outside alpha'));
        $this->assertTrue($filtered->contains('#' . $outside2 . ' · Outside beta'));
        $this->assertFalse($filtered->contains('Inside project'));

        // The concrete project ids keep filtering as before (no regression).
        $byProject = $admin->get('/?page=tasks&project=' . $projectId);
        $this->assertTrue($byProject->contains('Inside project'));
        $this->assertFalse($byProject->contains('Outside alpha'));
    }

    public function test_no_project_combines_with_project_ids_and_other_filters(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Apollo');
        [, , $outside1, $outside2] = $this->seedTasks($admin, $projectId);

        // Multi-select: keyword OR ids -> the whole board.
        $both = $admin->get('/?page=tasks&project[]=none&project[]=' . $projectId);
        $this->assertTrue($both->contains('Outside alpha'));
        $this->assertTrue($both->contains('Inside project'));

        // Keyword AND status filter -> only the matching unprojected task.
        $admin->post('/?page=edit&id=' . $outside1, [
            'action' => 'save_task',
            'id' => (string)$outside1,
            'title' => 'Outside alpha',
            'description' => 'Created by the test suite',
            'status_id' => '3',
            'parent_id' => '',
            'assignees' => [],
        ]);
        $combined = $admin->get('/?page=tasks&project=none&status=3');
        $this->assertTrue($combined->contains('#' . $outside1 . ' · Outside alpha'));
        $this->assertFalse($combined->contains('#' . $outside2 . ' · Outside beta'));
        $this->assertFalse($combined->contains('Inside project'));
    }

    public function test_filter_dropdown_offers_and_marks_the_no_project_option(): void
    {
        $admin = $this->adminSession();
        $list = $admin->get('/?page=tasks');
        $this->assertTrue($list->contains('<option value="none"'));
        $this->assertTrue($list->contains('>No project</option>'));

        $filtered = $admin->get('/?page=tasks&project=none');
        $this->assertTrue($filtered->contains('<option value="none" selected'));
    }

    public function test_bulk_form_and_pagination_links_carry_the_no_project_filter(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Apollo');
        $this->seedTasks($admin, $projectId);

        $filtered = $admin->get('/?page=tasks&project=none');
        // The bulk form re-emits the active filter as a hidden field, so the
        // bulk actions return to the same filtered view.
        $this->assertTrue($filtered->contains('name="project[]" value="none"'));
        // Sort headers keep the filter in their links too.
        $this->assertTrue($filtered->contains('href="?page=tasks&amp;project=none&amp;sort='));
    }

    public function test_unknown_project_filter_values_are_ignored(): void
    {
        $admin = $this->adminSession();
        $projectId = $this->createProject($admin, 'Apollo');
        $this->seedTasks($admin, $projectId);

        // A hand-typed non-id, non-keyword value is ignored (same convention
        // as every #25 filter): alone it filters nothing and shows the board.
        $bogus = $admin->get('/?page=tasks&project=bogus');
        $this->assertTrue($bogus->contains('Outside alpha'));
        $this->assertTrue($bogus->contains('Inside project'));
        // ...and it is simply dropped when combined with valid values.
        $mixed = $admin->get('/?page=tasks&project[]=bogus&project[]=none');
        $this->assertTrue($mixed->contains('Outside alpha'));
        $this->assertFalse($mixed->contains('Inside project'));
    }
}
