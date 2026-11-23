<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

/**
 * Task #45: a priority number on the tasks (default 1). The task form edits
 * it, the task page shows it and the task list gained a sortable Priority
 * column, so the number is visible where the work is picked up.
 */
final class TaskPriorityTest extends E2eTestCase
{
    private function priority(int $taskId): int
    {
        $q = TestServer::pdo()->prepare('SELECT priority FROM tasks WHERE id=?');
        $q->execute([$taskId]);
        $value = $q->fetchColumn();
        $q->closeCursor();
        return (int)$value;
    }

    public function test_priority_defaults_to_one_and_saves_from_the_form(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Plain item');
        $this->assertSame(1, $this->priority($taskId), 'A new task starts at priority 1.');

        $edited = $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Plain item',
            'description' => 'Created by the test suite', 'status_id' => '1',
            'parent_id' => '', 'priority' => '7', 'assignees' => [],
        ]);
        $this->assertSame(302, $edited->status);
        $this->assertSame(7, $this->priority($taskId));
        $this->assertStringContainsString('<strong>Priority:</strong> 7', $admin->taskPage($taskId)->body);

        // The edit form carries the stored value back out.
        $form = $admin->get('/?page=edit&id=' . $taskId);
        $this->assertStringContainsString('name="priority"', $form->body);
        $this->assertStringContainsString('value="7"', $form->body, 'The form prefills the stored priority.');
    }

    public function test_invalid_priority_is_rejected_and_keeps_the_old_value(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Guarded item');
        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Guarded item',
            'description' => 'x', 'status_id' => '1', 'parent_id' => '', 'priority' => '3', 'assignees' => [],
        ]);
        $this->assertSame(3, $this->priority($taskId));

        foreach (['0', '-1', '1001', 'high', '1.5'] as $bad) {
            $response = $admin->post('/?page=edit&id=' . $taskId, [
                'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Guarded item',
                'description' => 'x', 'status_id' => '1', 'parent_id' => '', 'priority' => $bad, 'assignees' => [],
            ]);
            $this->assertSame(200, $response->status);
            $this->assertTrue(
                $response->contains('Priority must be a whole number between 1 and 1000.'),
                'Priority ' . $bad . ' is rejected with a clear message.'
            );
        }
        $this->assertSame(3, $this->priority($taskId), 'A rejected priority is never stored.');
    }

    public function test_the_task_list_has_a_sortable_priority_column(): void
    {
        $admin = $this->adminSession();
        $low = $admin->createTask('Low priority item');
        $high = $admin->createTask('High priority item');
        $admin->post('/?page=edit&id=' . $high, [
            'action' => 'save_task', 'id' => (string)$high, 'title' => 'High priority item',
            'description' => 'x', 'status_id' => '1', 'parent_id' => '', 'priority' => '5', 'assignees' => [],
        ]);

        $body = $admin->get('/?')->body;
        $this->assertStringContainsString('Priority', $body, 'The Priority column is rendered.');
        $this->assertStringContainsString('sort=priority', $body, 'The Priority header is sortable.');
        $this->assertStringContainsString('&times;5</span>', $body, 'The high priority renders its number.');
        $this->assertStringContainsString('&times;1</span>', $body, 'The default priority renders as 1.');

        // Sorting by priority desc puts the priority-5 task on top of the other.
        $sorted = $admin->get('/?sort=priority&dir=desc')->body;
        $highPos = strpos($sorted, '#' . $high . ' · High priority item</a>');
        $lowPos = strpos($sorted, '#' . $low . ' · Low priority item</a>');
        $this->assertTrue($highPos !== false && $lowPos !== false && $highPos < $lowPos, 'Priority desc lists the highest priority first.');

        // Ascending flips the order.
        $asc = $admin->get('/?sort=priority&dir=asc')->body;
        $highPosAsc = strpos($asc, '#' . $high . ' · High priority item</a>');
        $lowPosAsc = strpos($asc, '#' . $low . ' · Low priority item</a>');
        $this->assertTrue($lowPosAsc < $highPosAsc, 'Priority asc lists the lowest priority first.');
    }

    public function test_priority_travels_through_the_url_filters_and_saved_searches(): void
    {
        // The priority sort key is part of the allowlisted list URL, so it can
        // travel in saved searches like every other sort key.
        $admin = $this->adminSession();
        $admin->createTask('Sort target');

        $response = $admin->post('/?page=tasks', [
            'action' => 'save_search', 'name' => 'By priority', 'q' => 'Sort', 'sort' => 'priority', 'dir' => 'desc',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=tasks&q=Sort&sort=priority&dir=desc', (string)$response->header('location'));

        $open = $admin->get('/?page=tasks&sort=priority&dir=desc');
        $this->assertSame(200, $open->status);
        $this->assertStringContainsString('&times;1</span>', $open->body, 'The sorted list renders the priority values.');

        // An unknown sort key is ignored (the allowlist), never injected.
        $injected = $admin->get('/?sort=priority;DROP%20TABLE%20tasks&dir=desc');
        $this->assertSame(200, $injected->status);
        $this->assertSame('3', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM statuses')->fetchColumn(), 'The statuses table is intact.');
    }
}
