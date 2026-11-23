<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #27: the task list gains a Project column, so every row shows which
 * project the task belongs to (linked, sortable, dash for no project).
 */
final class TaskProjectColumnTest extends E2eTestCase
{
    private function createProject(AppSession $admin, string $name): int
    {
        $response = $admin->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => $name,
            'description' => 'Project for the task list column test',
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

    private function createTaskInProject(AppSession $admin, string $title, int $projectId): int
    {
        $taskId = $admin->createTask($title);
        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task',
            'id' => (string)$taskId,
            'title' => $title,
            'description' => 'Created by the test suite',
            'status_id' => '1',
            'parent_id' => '',
            'project_id' => (string)$projectId,
            'assignees' => [],
        ]);
        return $taskId;
    }

    /** The exact anchor a task row renders for a project cell. */
    private function projectCell(int $projectId, string $name): string
    {
        return 'href="?page=project&id=' . $projectId . '">#' . $projectId . ' · ' . $name . '</a>';
    }

    public function test_task_list_shows_a_linked_project_column(): void
    {
        $admin = $this->adminSession();
        $apollo = $this->createProject($admin, 'Apollo');
        $borealis = $this->createProject($admin, 'Borealis');
        $this->createTaskInProject($admin, 'Alpha task', $apollo);
        $this->createTaskInProject($admin, 'Beta task', $borealis);
        $outside = $admin->createTask('Outside task');

        $list = $admin->get('/?page=tasks');

        // Sortable header.
        $this->assertTrue($list->contains('title="Order by Project"'));
        $this->assertTrue($list->contains('href="?page=tasks&amp;sort=project&amp;dir=asc"'));

        // Every project task row links to its project page; each link appears
        // exactly once (the filter dropdown renders options, not anchors).
        $this->assertSame(1, substr_count($list->body, $this->projectCell($apollo, 'Apollo')));
        $this->assertSame(1, substr_count($list->body, $this->projectCell($borealis, 'Borealis')));
        $this->assertSame(2, substr_count($list->body, 'href="?page=project&id='), 'Only the two project rows render project links.');

        // Filtering by a project keeps the column and hides the other project.
        $filtered = $admin->get('/?page=tasks&project=' . $apollo);
        $this->assertSame(1, substr_count($filtered->body, $this->projectCell($apollo, 'Apollo')));
        $this->assertSame(0, substr_count($filtered->body, $this->projectCell($borealis, 'Borealis')));
    }

    public function test_task_list_can_be_sorted_by_the_project_column(): void
    {
        $admin = $this->adminSession();
        $apollo = $this->createProject($admin, 'Apollo');
        $borealis = $this->createProject($admin, 'Borealis');
        $this->createTaskInProject($admin, 'Alpha task', $apollo);
        $this->createTaskInProject($admin, 'Beta task', $borealis);
        $admin->createTask('Outside task');

        $apolloCell = $this->projectCell($apollo, 'Apollo');
        $borealisCell = $this->projectCell($borealis, 'Borealis');

        // Ascending: tasks without a project first (NULL), then A..Z.
        $asc = $admin->get('/?page=tasks&sort=project&dir=asc');
        $posOutside = strpos($asc->body, '· Outside task</a>');
        $posApollo = strpos($asc->body, $apolloCell);
        $posBorealis = strpos($asc->body, $borealisCell);
        $this->assertNotFalse($posOutside);
        $this->assertNotFalse($posApollo);
        $this->assertNotFalse($posBorealis);
        $this->assertTrue($posOutside < $posApollo && $posApollo < $posBorealis, 'ASC order: no project, then Apollo, then Borealis.');

        // Descending: names reversed, tasks without a project last.
        $desc = $admin->get('/?page=tasks&sort=project&dir=desc');
        $posOutside = strpos($desc->body, '· Outside task</a>');
        $posApollo = strpos($desc->body, $apolloCell);
        $posBorealis = strpos($desc->body, $borealisCell);
        $this->assertTrue($posBorealis < $posApollo && $posApollo < $posOutside, 'DESC order: Borealis, then Apollo, then no project.');
    }

    public function test_empty_state_spans_the_new_column(): void
    {
        $admin = $this->adminSession();
        $none = $admin->get('/?page=tasks&q=does-not-exist-anywhere');
        // #45 added the Priority column after Project, so the empty state spans 9 columns now.
        $this->assertTrue($none->contains('<td colspan="9" class="text-center muted py-4">No tasks found.</td>'));
    }
}
