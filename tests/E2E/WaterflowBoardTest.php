<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #44: complexity estimation on tasks (default 1) and a dependency
 * waterflow chart page where every task's block width follows its complexity.
 * The chart reuses the task list's shared filter card and WHERE building
 * (tb_render_task_filter_card / tb_task_filters_where), like the kanban board.
 */
final class WaterflowBoardTest extends E2eTestCase
{
    private function complexity(int $taskId): int
    {
        $q = TestServer::pdo()->prepare('SELECT complexity FROM tasks WHERE id=?');
        $q->execute([$taskId]);
        $value = $q->fetchColumn();
        $q->closeCursor();
        return (int)$value;
    }

    /** Links $taskId depends_on $prerequisiteId through the real task page form. */
    private function linkDependsOn(AppSession $admin, int $taskId, int $prerequisiteId): void
    {
        $admin->post('/?page=task&id=' . $taskId, [
            'action' => 'link_task', 'task_id' => (string)$taskId,
            'related_task_id' => (string)$prerequisiteId, 'link_type' => 'depends_on',
        ]);
    }

    public function test_complexity_defaults_to_one_and_saves_from_the_form(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Plain item');
        $this->assertSame(1, $this->complexity($taskId), 'A new task starts at complexity 1.');

        $edited = $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Plain item',
            'description' => 'Created by the test suite', 'status_id' => '1',
            'parent_id' => '', 'complexity' => '5', 'assignees' => [],
        ]);
        $this->assertSame(302, $edited->status);
        $this->assertSame(5, $this->complexity($taskId));
        $this->assertStringContainsString('<strong>Complexity:</strong> 5', $admin->taskPage($taskId)->body);

        // The edit form carries the stored value back out.
        $form = $admin->get('/?page=edit&id=' . $taskId);
        $this->assertStringContainsString('name="complexity"', $form->body);
        $this->assertStringContainsString('value="5"', $form->body, 'The form prefills the stored complexity.');
    }

    public function test_invalid_complexity_is_rejected_and_keeps_the_old_value(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Guarded item');
        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Guarded item',
            'description' => 'x', 'status_id' => '1', 'parent_id' => '', 'complexity' => '3', 'assignees' => [],
        ]);
        $this->assertSame(3, $this->complexity($taskId));

        foreach (['0', '-2', '1001', 'abc', '2.5'] as $bad) {
            $response = $admin->post('/?page=edit&id=' . $taskId, [
                'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Guarded item',
                'description' => 'x', 'status_id' => '1', 'parent_id' => '', 'complexity' => $bad, 'assignees' => [],
            ]);
            $this->assertSame(200, $response->status);
            $this->assertTrue(
                $response->contains('Complexity must be a whole number between 1 and 1000.'),
                'Complexity ' . $bad . ' is rejected with a clear message.'
            );
        }
        $this->assertSame(3, $this->complexity($taskId), 'A rejected complexity is never stored.');
    }

    public function test_the_chart_orders_tasks_by_dependency_levels(): void
    {
        $admin = $this->adminSession();
        $independent = $admin->createTask('Independent item');
        $prerequisite = $admin->createTask('Prerequisite item');
        $dependent = $admin->createTask('Dependent item');
        $this->linkDependsOn($admin, $dependent, $prerequisite);

        $body = $admin->get('/?page=waterflow')->body;

        // Level rows are rendered top to bottom; every row is labelled.
        $this->assertStringContainsString('>Level 0</span>', $body);
        $this->assertStringContainsString('>Level 1</span>', $body);

        // The prerequisite and the independent task flow on level 0; the
        // dependent task lands on the level below its prerequisite.
        $level0 = strpos($body, '>Level 0</span>');
        $level1 = strpos($body, '>Level 1</span>');
        $prereqPos = strpos($body, '#' . $prerequisite . ' · Prerequisite item</a>');
        $indepPos = strpos($body, '#' . $independent . ' · Independent item</a>');
        $depPos = strpos($body, '#' . $dependent . ' · Dependent item</a>');
        $this->assertTrue($prereqPos > $level0 && $prereqPos < $level1, 'The prerequisite flows on level 0.');
        $this->assertTrue($indepPos > $level0 && $indepPos < $level1, 'Tasks without dependencies flow on level 0.');
        $this->assertTrue($depPos > $level1, 'The dependent task flows below its prerequisite.');
    }

    public function test_block_width_follows_the_complexity(): void
    {
        $admin = $this->adminSession();
        $small = $admin->createTask('Small item');
        $admin->post('/?page=edit&id=' . $small, [
            'action' => 'save_task', 'id' => (string)$small, 'title' => 'Small item',
            'description' => 'x', 'status_id' => '1', 'parent_id' => '', 'complexity' => '1', 'assignees' => [],
        ]);
        $big = $admin->createTask('Big item');
        $admin->post('/?page=edit&id=' . $big, [
            'action' => 'save_task', 'id' => (string)$big, 'title' => 'Big item',
            'description' => 'x', 'status_id' => '1', 'parent_id' => '', 'complexity' => '4', 'assignees' => [],
        ]);

        $body = $admin->get('/?page=waterflow')->body;
        $this->assertStringContainsString('style="width:100%', $body, 'The largest complexity in view takes the full width.');
        $this->assertStringContainsString('style="width:25%', $body, 'A quarter of the widest complexity takes a quarter of the width.');

        // The width style sits on the block that holds the task: verify pairing.
        foreach ([$big => 'width:100%', $small => 'width:25%'] as $taskId => $width) {
            $widthPos = strpos($body, $width);
            $linkPos = strpos($body, '#' . $taskId . ' · ');
            $this->assertTrue($widthPos !== false && $linkPos !== false && $widthPos < $linkPos && $linkPos - $widthPos < 300, 'The ' . $width . ' block holds task #' . $taskId . '.');
        }
    }

    public function test_dependencies_on_archived_tasks_do_not_block(): void
    {
        $admin = $this->adminSession();
        $archived = $admin->createTask('Gone item');
        $task = $admin->createTask('Waiting item');
        $this->linkDependsOn($admin, $task, $archived);
        $admin->post('/?page=task&id=' . $archived, ['action' => 'archive_task', 'id' => (string)$archived, 'return' => '?']);

        $body = $admin->get('/?page=waterflow')->body;
        // Only one level row exists: the waiting task is not held back.
        $this->assertStringNotContainsString('>Level 1</span>', $body, 'An archived prerequisite cannot block the flow.');
        $this->assertStringNotContainsString('Gone item', $body, 'Archived tasks do not appear in the chart.');
        $this->assertStringContainsString('Waiting item', $body);
    }

    public function test_the_chart_shares_the_filters_and_saved_searches(): void
    {
        $admin = $this->adminSession();
        $alpha = $admin->createTask('Alpha item');
        $admin->createTask('Beta item');

        $body = $admin->get('/?page=waterflow')->body;
        $this->assertStringContainsString('name="action" value="save_search"', $body, 'The save-search form is on the chart.');
        $this->assertStringContainsString('name="page" value="waterflow"', $body, 'The filter form submits back to the chart.');
        $this->assertStringContainsString('No saved searches yet.', $body);

        // The q search narrows the flow.
        $searched = $admin->get('/?page=waterflow&q=Alpha')->body;
        $this->assertStringContainsString('Alpha item', $searched);
        $this->assertStringNotContainsString('Beta item', $searched);

        // Saving from the chart stores the filters and returns to the chart.
        $response = $admin->post('/?page=waterflow', [
            'action' => 'save_search', 'name' => 'Flow alpha', 'q' => 'Alpha', 'save_page' => 'waterflow',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=waterflow&q=Alpha', (string)$response->header('location'), 'The redirect is the stored search link on the waterflow page.');
        $this->assertStringContainsString('href="?page=waterflow&amp;q=Alpha"', $admin->get('/?page=waterflow')->body, 'The dropdown opens the stored link on the chart.');
        $this->assertStringContainsString('href="?page=tasks&amp;q=Alpha"', $admin->get('/?page=tasks')->body, 'The same search opens on the task list from there.');

        // Deleting it returns to the chart.
        $id = (int)TestServer::pdo()->query("SELECT id FROM saved_searches WHERE name='Flow alpha'")->fetchColumn();
        $deleted = $admin->post('/?page=waterflow', [
            'action' => 'delete_search', 'id' => (string)$id, 'return' => '?page=waterflow&q=Alpha',
        ]);
        $this->assertSame('?page=waterflow&q=Alpha', (string)$deleted->header('location'));
    }

    public function test_the_chart_is_available_to_regular_users_and_in_the_menu(): void
    {
        $admin = $this->adminSession();
        $this->assertStringContainsString('href="?page=waterflow">Waterflow</a>', $admin->get('/?')->body, 'The navbar links the chart.');

        $admin->post('/?page=users', [
            'action' => 'add_user', 'username' => 'worker',
            'display_name' => 'Worker', 'password' => 'WorkerPass1234', 'role' => 'user',
        ]);
        $worker = new AppSession('worker', 'WorkerPass1234');
        $worker->createTask('Worker item');
        $page = $worker->get('/?page=waterflow');
        $this->assertSame(200, $page->status, 'The chart is a task view, not an admin page.');
        $this->assertStringContainsString('Worker item', $page->body);
    }

    public function test_the_chart_shows_the_project_in_focus_when_the_project_filter_is_set(): void
    {
        // #164: the task list and the kanban board show the picked project(s)
        // as a header badge with a one-click "Show all tasks" reset; the
        // waterflow chart renders the same badge from the same resolver, so a
        // filtered chart tells the visitor which project is in focus too.
        $admin = $this->adminSession();
        $admin->get('/?page=project_new');
        $created = $admin->post('/?page=project_new', [
            'action' => 'save_project', 'id' => '0', 'name' => 'Flow focus project',
            'description' => 'Created by the test suite', 'status_id' => '1', 'assignees' => [],
        ]);
        if (!preg_match('/page=project&id=(\d+)/', (string)$created->header('location'), $m)) {
            $this->fail('Project creation did not redirect to the project page.');
        }
        $projectId = (int)$m[1];
        $admin->get('/?page=new');
        $response = $admin->post('/?page=new', [
            'action' => 'save_task', 'id' => '0', 'title' => 'Flow item',
            'description' => 'Created by the test suite', 'status_id' => '1',
            'parent_id' => '', 'project_id' => (string)$projectId, 'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);

        $unfiltered = $admin->get('/?page=waterflow')->body;
        $this->assertStringNotContainsString('Project: ', $unfiltered, 'No badge renders without a project filter.');

        $filtered = $admin->get('/?page=waterflow&project=' . $projectId)->body;
        $this->assertStringContainsString('Project: Flow focus project', $filtered, 'The badge names the project in focus.');
        $this->assertStringContainsString('Show all tasks', $filtered, 'The badge carries the one-click reset.');
        $this->assertStringContainsString('href="?' . http_build_query(['page' => 'waterflow']) . '"', $filtered, 'The reset link points at the unfiltered chart.');
        $this->assertStringContainsString('Flow item', $filtered, 'The filtered chart still renders its block.');
    }
}
