<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\HttpResponse;

/**
 * End-to-end tests for the sortable task list: clicking a column name in
 * the Tasks list orders the list by that column (task #13).
 */
final class TaskSortingTest extends E2eTestCase
{
    /**
     * Seeds four tasks with distinct titles and statuses (TODO=1,
     * IN PROGRESS=2, DONE=3, in workflow order).
     * @return array{0: \TaskBoard\Tests\Support\AppSession, 1: int, 2: int, 3: int, 4: int}
     */
    private function seedTasks(): array
    {
        $admin = $this->adminSession();
        $alpha = $admin->createTask('Alpha item', ['status_id' => '2']);
        $beta = $admin->createTask('Beta item', ['status_id' => '1']);
        $gamma = $admin->createTask('Gamma item', ['status_id' => '3']);
        $delta = $admin->createTask('Delta item', ['status_id' => '1']);
        return [$admin, $alpha, $beta, $gamma, $delta];
    }

    /** Link text that identifies the main task link inside a task row. */
    private static function taskLink(int $id, string $title): string
    {
        return 'page=task&id=' . $id . '">#' . $id . ' · ' . $title . '</a>';
    }

    /**
     * Asserts the given needles appear in the body in the given order.
     * @param list<string> $needles
     */
    private function assertOrderedIn(HttpResponse $response, array $needles, string $message = ''): void
    {
        $positions = [];
        foreach ($needles as $needle) {
            $position = strpos($response->body, $needle);
            $this->assertNotFalse($position, 'Expected to find "' . $needle . '" in the page. ' . $message);
            $positions[] = $position;
        }
        for ($i = 1; $i < count($positions); $i++) {
            $this->assertTrue(
                $positions[$i] > $positions[$i - 1],
                $message . ' Expected "' . $needles[$i] . '" to appear after "' . $needles[$i - 1] . '".'
            );
        }
    }

    public function test_column_names_render_as_sortable_links(): void
    {
        [$admin] = $this->seedTasks();

        $page = $admin->get('/?page=tasks');
        foreach (['Task', 'Status', 'Assignees', 'Parent', 'Updated'] as $label) {
            $this->assertTrue($page->contains('Order by ' . $label), 'Missing sortable header for "' . $label . '".');
        }
        $this->assertTrue($page->contains('sort=id'), 'Task header should link to sort=id.');
        $this->assertTrue($page->contains('sort=status'), 'Status header should link to sort=status.');
        $this->assertTrue($page->contains('sort=assignees'), 'Assignees header should link to sort=assignees.');
        $this->assertTrue($page->contains('sort=parent'), 'Parent header should link to sort=parent.');
        $this->assertTrue($page->contains('sort=updated'), 'Updated header should link to sort=updated.');
        // The Actions column has no data to sort by and stays a plain header.
        $this->assertFalse($page->contains('Order by Actions'));
    }

    public function test_default_order_is_most_recently_updated_first(): void
    {
        [$admin, $alpha, $beta, $gamma, $delta] = $this->seedTasks();

        $page = $admin->get('/?page=tasks');
        $this->assertOrderedIn($page, [
            self::taskLink($delta, 'Delta item'),
            self::taskLink($gamma, 'Gamma item'),
            self::taskLink($beta, 'Beta item'),
            self::taskLink($alpha, 'Alpha item'),
        ], 'Default order should be updated DESC with the task id as a stable tiebreaker.');
    }

    public function test_sort_by_task_number_ascending_then_descending(): void
    {
        [$admin, $alpha, $beta, $gamma, $delta] = $this->seedTasks();

        $asc = $admin->get('/?page=tasks&sort=id&dir=asc');
        $this->assertOrderedIn($asc, [
            self::taskLink($alpha, 'Alpha item'),
            self::taskLink($beta, 'Beta item'),
            self::taskLink($gamma, 'Gamma item'),
            self::taskLink($delta, 'Delta item'),
        ], 'sort=id&dir=asc should show the oldest tasks first.');

        $desc = $admin->get('/?page=tasks&sort=id&dir=desc');
        $this->assertOrderedIn($desc, [
            self::taskLink($delta, 'Delta item'),
            self::taskLink($gamma, 'Gamma item'),
            self::taskLink($beta, 'Beta item'),
            self::taskLink($alpha, 'Alpha item'),
        ], 'sort=id&dir=desc should show the newest tasks first.');
    }

    public function test_sort_by_title_alphabetically(): void
    {
        [$admin, $alpha, $beta, $gamma, $delta] = $this->seedTasks();

        $asc = $admin->get('/?page=tasks&sort=title&dir=asc');
        $this->assertOrderedIn($asc, [
            self::taskLink($alpha, 'Alpha item'),
            self::taskLink($beta, 'Beta item'),
            self::taskLink($delta, 'Delta item'),
            self::taskLink($gamma, 'Gamma item'),
        ], 'sort=title&dir=asc should order titles alphabetically.');
    }

    public function test_sort_by_status_follows_the_workflow_order(): void
    {
        [$admin, $alpha, $beta, $gamma, $delta] = $this->seedTasks();

        $asc = $admin->get('/?page=tasks&sort=status&dir=asc');
        // TODO (sort_order 1) first, then IN PROGRESS (2), then DONE (3);
        // within one status the tasks are ordered by task id ascending.
        $this->assertOrderedIn($asc, [
            self::taskLink($beta, 'Beta item'),
            self::taskLink($delta, 'Delta item'),
            self::taskLink($alpha, 'Alpha item'),
            self::taskLink($gamma, 'Gamma item'),
        ], 'sort=status&dir=asc should order by the status workflow order.');

        $desc = $admin->get('/?page=tasks&sort=status&dir=desc');
        $this->assertOrderedIn($desc, [
            self::taskLink($gamma, 'Gamma item'),
            self::taskLink($alpha, 'Alpha item'),
            self::taskLink($delta, 'Delta item'),
            self::taskLink($beta, 'Beta item'),
        ], 'sort=status&dir=desc should reverse the workflow order.');
    }

    public function test_sort_by_assignees_orders_by_assignee_name(): void
    {
        $admin = $this->adminSession();
        // The admin is user id 1; create two more users with names that
        // sort the same way in case-sensitive and case-insensitive collations.
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'alice',
            'display_name' => 'AAA Person',
            'password' => 'UserPass12345',
            'role' => 'user',
        ]);
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'zoe',
            'display_name' => 'ZZZ Person',
            'password' => 'UserPass12345',
            'role' => 'user',
        ]);
        $plain = $admin->createTask('Plain task');
        $withAaa = $admin->createTask('Assigned to AAA', ['assignees' => ['2']]);
        $withZzz = $admin->createTask('Assigned to ZZZ', ['assignees' => ['3']]);

        $asc = $admin->get('/?page=tasks&sort=assignees&dir=asc');
        $this->assertOrderedIn($asc, [
            self::taskLink($plain, 'Plain task'),
            self::taskLink($withAaa, 'Assigned to AAA'),
            self::taskLink($withZzz, 'Assigned to ZZZ'),
        ], 'sort=assignees&dir=asc: unassigned first, then by first assignee name.');
    }

    public function test_sort_by_parent_groups_the_children(): void
    {
        $admin = $this->adminSession();
        $parentOne = $admin->createTask('Parent one');
        $parentTwo = $admin->createTask('Parent two');
        $childOfTwo = $admin->createTask('Child of two', ['parent_id' => (string)$parentTwo]);
        $childOfOne = $admin->createTask('Child of one', ['parent_id' => (string)$parentOne]);

        $asc = $admin->get('/?page=tasks&sort=parent&dir=asc');
        $this->assertOrderedIn($asc, [
            self::taskLink($parentOne, 'Parent one'),
            self::taskLink($parentTwo, 'Parent two'),
            self::taskLink($childOfOne, 'Child of one'),
            self::taskLink($childOfTwo, 'Child of two'),
        ], 'sort=parent&dir=asc: parentless tasks first, then grouped by parent task.');
    }

    public function test_sort_survives_pagination(): void
    {
        $admin = $this->adminSession();
        $count = 21; // one more than the 20-per-page list
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $admin->createTask('Sortable task ' . sprintf('%02d', $i));
        }

        $pageOne = $admin->get('/?page=tasks&sort=id&dir=asc');
        $this->assertOrderedIn($pageOne, [
            self::taskLink($ids[0], 'Sortable task 01'),
            self::taskLink($ids[19], 'Sortable task 20'),
        ], 'Page one in ascending order should start with the first tasks.');
        $this->assertTrue($pageOne->contains('sort=id&amp;dir=asc'), 'The pagination links must keep the chosen sort.');
        $this->assertTrue($pageOne->contains('Page 1 of 2'));

        $pageTwo = $admin->get('/?page=tasks&sort=id&dir=asc&p=2');
        $this->assertTrue($pageTwo->contains(self::taskLink($ids[20], 'Sortable task 21')), 'Page two shows the 21st task.');
        $this->assertFalse($pageTwo->contains(self::taskLink($ids[0], 'Sortable task 01')), 'The first task belongs to page one.');
        $this->assertFalse($pageTwo->contains(self::taskLink($ids[19], 'Sortable task 20')), 'The 20th task is the last row of page one.');
    }

    public function test_sorting_keeps_active_filters(): void
    {
        [$admin, $alpha, $beta] = $this->seedTasks();

        $filtered = $admin->get('/?page=tasks&status=1&sort=id&dir=asc');
        $this->assertTrue($filtered->contains(self::taskLink($beta, 'Beta item')));
        $this->assertFalse($filtered->contains(self::taskLink($alpha, 'Alpha item')));
        // The header links must preserve the active status filter.
        $this->assertTrue($filtered->contains('status=1&amp;sort=id'), 'Header links keep the status filter.');
        $this->assertTrue($filtered->contains('status=1&amp;sort=status'), 'Header links keep the status filter.');
    }

    public function test_unknown_sort_parameters_fall_back_to_the_default(): void
    {
        [$admin, $alpha, $beta, $gamma, $delta] = $this->seedTasks();

        $page = $admin->get('/?page=tasks&sort=(SELECT%201)&dir=javascript:alert(1)');
        $this->assertSame(200, $page->status);
        $this->assertOrderedIn($page, [
            self::taskLink($delta, 'Delta item'),
            self::taskLink($gamma, 'Gamma item'),
            self::taskLink($beta, 'Beta item'),
            self::taskLink($alpha, 'Alpha item'),
        ], 'Unknown sort parameters must fall back to the default order, never reach SQL.');
    }

    public function test_clicking_the_active_column_flips_the_direction(): void
    {
        [$admin] = $this->seedTasks();

        $asc = $admin->get('/?page=tasks&sort=id&dir=asc');
        // The active column is ascending, so its own header links to descending.
        $this->assertTrue($asc->contains('aria-sort="ascending"'), 'The active column exposes aria-sort.');
        $this->assertTrue($asc->contains('sort=id&amp;dir=desc'), 'Clicking the active ascending column flips to descending.');
        $this->assertTrue($asc->contains('&#9650;'), 'The ascending column shows an up arrow.');

        $desc = $admin->get('/?page=tasks&sort=id&dir=desc');
        $this->assertTrue($desc->contains('aria-sort="descending"'), 'The active column exposes aria-sort.');
        $this->assertTrue($desc->contains('sort=id&amp;dir=asc'), 'Clicking the active descending column flips to ascending.');
        $this->assertTrue($desc->contains('&#9660;'), 'The descending column shows a down arrow.');
    }
}
